<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
saas_require_login();
saas_require_organization();
saas_require_tour();
saas_require_permission('settings.manage');
saas_redirect('tour_edit.php');
