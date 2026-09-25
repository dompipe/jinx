<?php

declare(strict_types=1);

$result = parse_url('https://xadz.click/path?q=jinx', PHP_URL_HOST);
echo 'parse_url=' . $result;
return $result;
