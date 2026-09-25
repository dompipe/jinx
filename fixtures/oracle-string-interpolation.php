<?php

declare(strict_types=1);

$name = 'JINX';
$verb = 'flies';
$row = [];
$row['name'] = 'Oracle';
$row['count'] = 7;

$line = "Hello $name, $row[name] $verb with $row[name] #$row[count]";
$plain = "Dollar stays: \$name";
$offset = "Simple offset: $row[name] / $row[count]";

print "$line\n";
echo "$plain\n";
echo "$offset\n";

return "Return $name $row[name] $row[name] $row[count]";
