<?php
require 'BCAParser.php';
$result = [];

if(!isset($_POST["UserID"]) || !isset($_POST["PIN"])){
	$result['status'] = "01";
	$result['error'] = "UserID dan PIN tidak boleh kosong";
} else {
	$username = $_POST["UserID"];
	$password = $_POST["PIN"];
	$parser = new BCAParser($username, $password);
	if(empty($parser->errorMessage)){
		$result['status'] = "00";
		$result['transactions'] = $parser->transactions;
		$result['balance'] = $parser->balance;
		$result['accountNo'] = $parser->accountNo;
		$result['owner'] = $parser->accountOwner;
	} else {
		$result['status'] = "01";
		$result['error'] = $parser->errorMessage;
	}
}

echo json_encode($result);