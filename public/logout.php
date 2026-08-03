<?php
/* Sign out, then back to the login screen.
 *
 * POST only. A GET would let any <img src="logout.php"> on any page sign you
 * out, which is harmless but baffling — and browsers prefetch links. */

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';

/* POST, but NOT require_same_origin(). That check demands an X-Requested-With
 * header, which a plain <form> cannot send — it would make signing out depend
 * on JavaScript. The thing it protects against here is someone tricking you
 * into signing out, which is an annoyance rather than a breach: it destroys a
 * session, it can't read or change anything. */
noindex();
require_method('POST');

auth_logout();
header('Location: login.php');
exit;
