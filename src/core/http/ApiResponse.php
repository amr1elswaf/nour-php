<?php
namespace Nour\core\http;

//use Error;
use ErrorException;
final class ApiResponse extends ErrorException {
    public function __construct() {
        parent::__construct();    
    }
}