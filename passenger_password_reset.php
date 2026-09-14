<?php
// Password reset flow will be implemented in the auth hardening pass.
require_once __DIR__.'/bootstrap_saas.php';
http_response_code(501);
exit('Password reset is not configured yet.');
