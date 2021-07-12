<?php

define('DSPACE_URL',        'http://localhost:8080/server');
define('DSPACE_USERNAME',   'dspacedemo+admin@gmail.com');
define('DSPACE_PASSWORD',   'dspace');

require_once("DSpaceRest.php");

$dspace = new DSpaceRest(DSPACE_URL, DSPACE_USERNAME, DSPACE_PASSWORD);

foreach ($dspace->get_all_items('id', 20) as $item) {
    echo $item->id ."\n";
}