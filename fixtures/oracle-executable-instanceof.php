<?php

declare(strict_types=1);

class OracleAnimal
{
}

class OracleDog extends OracleAnimal
{
}

class OracleRock
{
}

$dog = new OracleDog();
$isDog = $dog instanceof OracleDog;
$isAnimal = $dog instanceof OracleAnimal;
$isRock = $dog instanceof OracleRock;
$text = $isDog . ':' . $isAnimal . ':' . $isRock;

echo $text;

return $text;
