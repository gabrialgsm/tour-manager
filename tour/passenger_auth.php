<?php
declare(strict_types=1);
// Compatibility route: some public tour links may point to /tour/passenger_auth.php.
header('Location: /passenger_auth.php', true, 302);
exit;
