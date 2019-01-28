<?php
namespace SimpleComments;

use Grav\Common\Grav;
use Grav\Plugin\SimpleCommentsPlugin;
use Grav\Common\Assets;
use RocketTheme\Toolbox\Event\Event;

interface Manager {

  public function __construct(Grav $grav, SimpleCommentsPlugin $plugin);

  public function getRequiredPermission();

  public function getLocation();

  public function getNav();

  public function initializeAssets(Assets $assets);

  public function handleTask(Event $event);

  public function handleAdminRequest();

  public function handleClientRequest();

}
