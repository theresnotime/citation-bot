<?php
set_time_limit(240);
require_once 'html_headers.php';

ob_implicit_flush();
echo '<!DOCTYPE html><html lang="en" dir="ltr"><head><title>PHP Info</title></head><body><pre>';

if (password_verify($_REQUEST['p'], '$2y$10$UOmZtkKs1X17vE/mmbfVgOiy0ZAkXnxa9UxFO97cFX8ioiJZpZ96S')) {
  set_time_limit(240);
  unset($_REQUEST['p'], $_GET['p'], $_POST['p'], $_SERVER['HTTP_X_ORIGINAL_URI'], $_SERVER['REQUEST_URI'], $_SERVER['QUERY_STRING']); // Anything that contains password string
  phpinfo(INFO_ALL);
  set_time_limit(240);
  /** @psalm-suppress ForbiddenCode */
  set_time_limit(240);
  echo "\n\n" . htmlspecialchars((string) shell_exec("(/bin/rm -rf ../.nfs00000000050c0a6700000001)  2>&1"), ENT_QUOTES);
  set_time_limit(240);
  echo "\n\n" . htmlspecialchars((string) shell_exec("(/bin/ls -lahtr . ..)  2>&1"), ENT_QUOTES);
  set_time_limit(240);
  echo "\n service.template \n" . htmlspecialchars((string) shell_exec("(/bin/cat ../service.template)  2>&1"), ENT_QUOTES);
  set_time_limit(240);
  echo "\n service.manifest \n" . htmlspecialchars((string) shell_exec("(/bin/cat ../service.manifest) 2>&1"), ENT_QUOTES);
  set_time_limit(240);
}

echo '</pre></body></html>';
?>
