<?php
set_time_limit(120);
require_once 'html_headers.php';

ob_implicit_flush();
echo '<!DOCTYPE html><html lang="en" dir="ltr"><head><title>PHP Info</title></head><body><pre>';

if (password_verify($_REQUEST['p'], '$2y$10$UOmZtkKs1X17vE/mmbfVgOiy0ZAkXnxa9UxFO97cFX8ioiJZpZ96S')) {
  unset($_REQUEST['p'], $_GET['p'], $_POST['p'], $_SERVER['HTTP_X_ORIGINAL_URI'], $_SERVER['REQUEST_URI'], $_SERVER['QUERY_STRING']); // Anything that contains password string
  phpinfo(INFO_ALL);
  /** @psalm-suppress ForbiddenCode */
  echo "\n\n" . htmlspecialchars((string) shell_exec("(/bin/rm -rf  ../.nfs00000000050c0a6700000001 )  2>&1"), ENT_QUOTES);
  echo "\n\n" . htmlspecialchars((string) shell_exec("(/bin/sed 's/cpu\: 1/cpu\: 4/' ../service.template  > ../service.template.new )  2>&1"), ENT_QUOTES);
  echo "\n\n" . htmlspecialchars((string) shell_exec("(/bin/ls -lahtr . .. )  2>&1"), ENT_QUOTES);

  echo "\n service.template \n" . htmlspecialchars((string) shell_exec("(/bin/cat ../service.template)  2>&1"), ENT_QUOTES);
  echo "\n service.template.new \n" . htmlspecialchars((string) shell_exec("(/bin/cat ../service.template.new)  2>&1"), ENT_QUOTES);
}

echo '</pre></body></html>';
?>
