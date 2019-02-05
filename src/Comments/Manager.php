<?php
namespace SimpleComments\Comments;

use Grav\Common\Assets;
use Grav\Common\Data\Blueprints;
use Grav\Common\Filesystem\RecursiveFolderFilterIterator;
use Grav\Common\Grav;
use Grav\Common\User\User;
use Grav\Common\Utils;
use Grav\Plugin\SimpleCommentsPlugin;
use RocketTheme\Toolbox\Event\Event;
use RocketTheme\Toolbox\File\File;
use SimpleComments\Manager as IManager;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\ExpressionLanguage\ExpressionFunction;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use Symfony\Component\Yaml\Yaml;

class Manager implements IManager, EventSubscriberInterface {

  private $grav;
  private $plugin;
  private $adminController;


  public static $instance;

  public function __construct(Grav $grav, SimpleCommentsPlugin $plugin)
  {
    $this->grav = $grav;
    $this->plugin = $plugin;
    $this->config = $grav['config']->get('plugins.simple-comments');

    self::$instance = $this;

    $this->grav['events']->addSubscriber($this);
  }

  public static function getSubscribedEvents()
  {
    return [
      'onAdminControllerInit' => ['onAdminControllerInit', 0],
      'onAdminData' => ['onAdminData', 0]
    ];
  }

  public function onAdminControllerInit($e)
  {
    $controller = $e['controller'];
    $this->adminController = $controller;
  }

  public function onAdminData($e)
  {
    $type = $e['type'];
  }

  public function getRequiredPermission()
  {
    return $this->plugin->name . '.edit';
  }

  public function getLocation()
  {
    return 'simple-comments';
  }

  public function getNav()
  {
    return [
      'label' => 'Simple Comments',
      'location' => $this->getLocation(),
      'icon' => 'fa-comments',
      'authorize' => $this->getRequiredPermission(),
    ];
  }


  public function initializeAssets(Assets $assets)
  {
  }

  public function handleTask(Event $event)
  {
    return false;
  }

  public function handleAdminRequest()
  {
    $vars = [
      'plugin_admin_path' => $this->getLocation(),
    ];

    $twig = $this->grav['twig'];
    $uri = $this->grav['uri'];
    $filepath = null;

    if (isset($uri->paths()[2])) {
      $commentsPagePath = $uri->paths();
      $commentsPagePath = implode('/', array_splice($commentsPagePath, 2));
      $filepath = $this->plugin->getDataStoragePath() . '/' . $commentsPagePath . '.yaml';

      if (!file_exists($filepath)) {
        $filepath = null;
      }
    }

    if ($filepath) {
      $data = Yaml::parse(file_get_contents($filepath));

      if (isset($_POST['comments'])) {
        $data['comments'] = array_reverse($_POST['comments']);
        file_put_contents($filepath, Yaml::dump($data, 10));
        $this->grav->redirect($this->plugin->getPreviousUrl());
      } else if (isset($_POST['form-nonce'])) {
        unlink($filepath);
        $this->grav->redirect('/admin/'.$this->getLocation());
      }

      $vars['comments_page_path'] = $commentsPagePath;
      $vars['comments'] = array_reverse($data['comments']);

      $blueprints = new Blueprints;
      $vars['blueprint'] = $blueprints->get('comments/comments');

    } else {
      $vars['pages_with_comments'] = $this->getPagesWithComments();
      $vars['latest_comments'] = $this->getLatestComments();
    }

    return $vars;
  }

  public function handleClientRequest()
  {
    $vars = [];
    $twig = $this->grav['twig'];
    $uri = $this->grav['uri'];

    $commentsPagePath = trim($uri->path(), '/');
    $filepath = $this->plugin->getDataStoragePath() . '/' . $commentsPagePath . '.yaml';

    if (isset($_POST) && isset($_POST['action']) && $_POST['action'] === 'addComment') {
      $post = isset($_POST['data']) ? $_POST['data'] : [];

      $name = filter_var(urldecode($post['name']), FILTER_SANITIZE_STRING);
      if (!isset($this->config['email_field_enabled']) || $this->config['email_field_enabled'] == 1) {
        $email = filter_var(urldecode($post['email']), FILTER_SANITIZE_STRING);
      } else {
        $email = '';
      }
      $text = filter_var(urldecode($post['comment']), FILTER_SANITIZE_STRING);

      if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        $remote_address = $_SERVER['HTTP_CLIENT_IP'];
      } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $remote_address = $_SERVER['HTTP_X_FORWARDED_FOR'];
      } else {
        $remote_address = $_SERVER['REMOTE_ADDR'];
      }

      $comment = array(
        'author' => $name,
        'email' => $email,
        'text' => $text,
        'date' => date('D, d M Y H:i:s', time()),
        'remote_address' => $remote_address,
      );

      if (!file_exists($filepath)) {
        $data = array(
          'title' => $this->grav['page']->title(),
          'comments' => [ $comment ],
        );
      } else {
        $data = Yaml::parse(file_get_contents($filepath));
        $data['comments'][] = $comment;
      }

      $storageDir = pathinfo($filepath);
      if (!is_dir($storageDir['dirname'])) {
        mkdir($storageDir['dirname'], 0644, true);
      }
      file_put_contents($filepath, Yaml::dump($data, 10));
      $this->grav->redirect($this->grav['page']->url() . '#Comments');
      return;
    }

    if (!file_exists($filepath)) {
      $vars['simple_comments'] = array(
        'title' => '',
        'comments' => [],
      );
    } else {
      $data = Yaml::parse(file_get_contents($filepath));
      $data['comments'] = array_reverse($data['comments']);
      $vars['simple_comments'] = $data;
    }

    return $vars;
  }

  private function getLatestComments()
  {
    $pages = $this->getPagesWithComments();
    $comments = [];
    foreach ($pages as $page) {
      $data = Yaml::parse(file_get_contents($page->file['path']));
      for($i = 0; $i < count($data['comments']); $i += 1) {
        $data['comments'][$i]['page'] = $page;
      }
      $comments = array_merge($comments, $data['comments']);

    }
    usort($comments, function($a, $b) {
      return (strtotime($a['date']) < strtotime($b['date']));
    });

    return array_slice($comments, 0, 10);
  }

  private function getPagesWithComments($path = '')
  {
    $files = [];

    if (!$path) {
      $path = $this->plugin->getDataStoragePath();
    }

    $dirItr     = new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS);
    $filterItr  = new RecursiveFolderFilterIterator($dirItr);
    $itr        = new \RecursiveIteratorIterator($filterItr, \RecursiveIteratorIterator::SELF_FIRST);
    $itrItr     = new \RecursiveIteratorIterator($dirItr, \RecursiveIteratorIterator::SELF_FIRST);
    $filesItr   = new \RegexIterator($itrItr, '/^.+\.yaml$/i');

    foreach ($filesItr as $filepath => $file) {
      $data = Yaml::parse(file_get_contents($filepath));
      $amountOfComments = isset($data['comments']) ? count($data['comments']) : 0;
      $page = trim(str_replace([$path, '.yaml'], '', $file->getPath() . DS . $file->getFilename()), '/');
      $editUrl = $this->grav['config']->get('plugins.admin.route') . '/' . $this->getLocation() . '/' . $page;

      $files[] = (object)array(
        "modifiedDate" => $file->getMTime(),
        "page" => $page,
        "amountOfComments" => $amountOfComments,
        "editUrl" => $editUrl,
        "file" => array(
          "name" => $file->getFilename(),
          "path" => $filepath,
        ),
      );
    }

    foreach ($itr as $file) {
      if ($file->isDir()) {
        $this->getPagesWithComments($file->getPath() . '/' . $file->getFilename());
      }
    }

    usort($files, function($a, $b) {
      return strcmp($a->page, $b->page);
    });

    return $files;
  }


}
