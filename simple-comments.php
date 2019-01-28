<?php
namespace Grav\Plugin;

use Grav\Common\User\User;
use Grav\Common\Utils;
use Grav\Common\File\CompiledYamlFile;
use Grav\Common\Filesystem\Folder;
use Grav\Common\Plugin;
use RocketTheme\Toolbox\Event\Event;
use RocketTheme\Toolbox\File\File;
use SimpleComments\Comments\Manager as CommentsManager;

class SimpleCommentsPlugin extends Plugin
{

  public function getPluginConfigKey($key = null)
  {
    $pluginKey = 'plugins.' . $this->name;

    return ($key !== null) ? $pluginKey . '.' . $key : $pluginKey;
  }

  public function getPluginConfigValue($key = null, $default = null)
  {
    return $this->config->get($this->getPluginConfigKey($key), $default);
  }

  public function getConfigValue($key, $default = null)
  {
    return $this->config->get($key, $default);
  }

  public function getPreviousUrl()
  {
    return $this->grav['session']->{$this->name . '.previous_url'};
  }

  public function getDataStoragePath()
  {
    $path =  DATA_DIR . 'simple-comments';
    if (!file_exists($path)) {
      Folder::mkdir($path);
    }
    return $path;
  }

  public static function getSubscribedEvents()
  {
    return [
     'onPluginsInitialized' => ['onPluginsInitialized', 0],
     'onAdminRegisterPermissions' => ['onAdminRegisterPermissions', 1000]
    ];
  }

  public function onPluginsInitialized()
  {
    include __DIR__ . DS . 'vendor' . DS . 'autoload.php';

    if ($this->isAdmin()) {
      return $this->initializeAdmin();
    } else {
      return $this->initializeClient();
    }
  }

  /** Client */

  public function initializeClient()
  {
    $this->grav['locator']->addPath('blueprints', '', __DIR__ . DS . 'blueprints');

    $this->managers[] = new CommentsManager($this->grav, $this);

    $this->enable([
      'onTwigTemplatePaths' => ['onTwigTemplatePaths', 0],
      'onPageInitialized' => ['onPageInitialized', 10],
      'onTwigSiteVariables' => ['onClientTwigSiteVariables', 0],
    ]);


    $cache = $this->grav['cache'];
    $uri = $this->grav['uri'];
    //init cache id
    $this->simple_comments_cache_id = md5('comments-data' . $cache->getKey() . '-' . $uri->url());
  }

  public function onTwigTemplatePaths()
  {
    $this->grav['twig']->twig_paths[] = __DIR__ . '/templates';
  }

  public function onPageInitialized()
  {

  }

  public function onClientTwigSiteVariables()
  {
    $page = $this->grav['page'];
    $twig = $this->grav['twig'];
    $uri = $this->grav['uri'];

    foreach ($this->managers as $manager) {
      $vars = $manager->handleClientRequest();
      $twig->twig_vars = array_merge($twig->twig_vars, $vars);
      // print_r($twig->twig_vars);
      return true;
    }
  }

  /** Admin */

  public function initializeAdmin()
  {
    $this->grav['locator']->addPath('blueprints', '', __DIR__ . DS . 'blueprints');

    $this->managers[] = new CommentsManager($this->grav, $this);

    $this->enable([
     'onAdminTwigTemplatePaths' => ['onAdminTwigTemplatePaths', 0],
     'onTwigSiteVariables' => ['onAdminTwigSiteVariables', 0],
     'onAdminMenu' => ['onAdminMenu', 0],
     'onAssetsInitialized' => ['onAssetsInitialized', 0],
     'onAdminTaskExecute' => ['onAdminTaskExecute', 0],
    ]);

    $this->registerPermissions();
  }

  public function onAdminMenu()
  {
    $twig = $this->grav['twig'];
    $twig->plugins_hooked_nav = (isset($twig->plugins_hooked_nav)) ? $twig->plugins_hooked_nav : [];

    foreach ($this->managers as $manager) {
      $nav = $manager->getNav();
      if ($nav) {
        $twig->plugins_hooked_nav[$nav['label']] = $nav;
      }
    }
  }

  public function onAdminTwigTemplatePaths($e)
  {
    $paths = $e['paths'];
    $paths[] = __DIR__ . DS . 'admin' . DS . 'templates';
    $e['paths'] = $paths;
  }

  public function onAdminTwigSiteVariables()
  {
    $page = $this->grav['page'];
    $twig = $this->grav['twig'];
    $session = $this->grav['session'];
    $uri = $this->grav['uri'];

    foreach ($this->managers as $manager) {
     if ($page->slug() === $manager->getLocation() && $this->grav['admin']->authorize(['admin.super', $manager->getRequiredPermission()])) {
       $session->{$this->name . '.previous_url'} = $uri->route() . $uri->params();

       $page = $this->grav['admin']->page(true);
       $twig->twig_vars['context'] = $page;

       $vars = $manager->handleAdminRequest();
       $twig->twig_vars = array_merge($twig->twig_vars, $vars);

       return true;
     }
    }
  }

  public function onAssetsInitialized()
  {
    $assets = $this->grav['assets'];

    foreach ($this->managers as $manager) {
      $manager->initializeAssets($assets);
    }
  }

  public function onAdminTaskExecute($e)
  {
    foreach ($this->managers as $manager) {
      if ($this->grav['admin']->authorize(['admin.super', $manager->getRequiredPermission()])) {
        $result = $manager->handleTask($e);

        if ($result) {
          return true;
        }
      }
    }

    return false;
  }

  public function registerPermissions()
  {
    foreach ($this->managers as $manager) {
      $this->grav['admin']->addPermissions([$manager->getRequiredPermission() => 'boolean']);
    }

    // Custom permissions
    $customPermissions = $this->getPluginConfigValue('custom_permissions', []);
    foreach ($customPermissions as $permission) {
      $this->grav['admin']->addPermissions([$permission => 'boolean']);
    }
  }

  public function onAdminRegisterPermissions()
  {
    if (!$this->isAdmin() || !$this->grav['user']->authenticated) {
      return;
    }

    $this->grav['admin']->addPermissions(['site.login' => 'boolean']);
  }
}
