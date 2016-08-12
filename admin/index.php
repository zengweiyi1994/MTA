<?php
require_once("../inc/common.php");
try
{
    $content .= "<h1>Admin Pages</h1>";
    $content .= "<a href='admin/coursemanager.php'>Course Manager</a><br>";
    $content .= "<a href='admin/usermanager.php'>User Manager</a><br>";
    render_page();
}catch(Exception $e){
    render_exception_page($e);
}


