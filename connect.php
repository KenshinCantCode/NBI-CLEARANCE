<?php

$host="localhost";
$user="root";
$pass="";
$db="nbi_clearance";
$conn=new mysqli($host,$user,$pass,$db);
if($conn->connect_error){
    die("Failed to connect DB: " . $conn->connect_error);
}
$conn->set_charset("utf8mb4");
?>
