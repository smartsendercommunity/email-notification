<?php

// ver 1.0 - 05.03.21

ini_set('max_execution_time', '1700');
set_time_limit(1700);


header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE');
header('Access-Control-Allow-Headers: application/json');
header('Content-Type: application/json; charset=utf-8');

http_response_code(200);

{
function send_forward($inputJSON, $link){
	
$request = 'POST';	
		
$descriptor = curl_init($link);

 curl_setopt($descriptor, CURLOPT_POSTFIELDS, $inputJSON);
 curl_setopt($descriptor, CURLOPT_RETURNTRANSFER, 1);
 curl_setopt($descriptor, CURLOPT_HTTPHEADER, array('Content-Type: application/json')); 
 curl_setopt($descriptor, CURLOPT_CUSTOMREQUEST, $request);

    $itog = curl_exec($descriptor);
    curl_close($descriptor);

   		 return $itog;
		
}
function get_bearer($token, $link){
	
$request = 'GET';	
		
$descriptor = curl_init($link);

 curl_setopt($descriptor, CURLOPT_RETURNTRANSFER, 1);
 curl_setopt($descriptor, CURLOPT_HTTPHEADER, array('Content-Type: application/json', 'Authorization: Bearer '.$token)); 
 curl_setopt($descriptor, CURLOPT_CUSTOMREQUEST, $request);

    $itog = curl_exec($descriptor);
    curl_close($descriptor);

   		 return $itog;
		
}
}

$inputJSON = file_get_contents('php://input');
$input = json_decode($inputJSON, TRUE); //convert JSON into array
$headers = apache_request_headers();

// Определение местоположения файла
$dir = dirname($_SERVER["PHP_SELF"]);
$url = ((!empty($_SERVER["HTTPS"])) ? "https" : "http") . "://" . $_SERVER["HTTP_HOST"] . $dir;
$url = explode("?", $url);
$url = $url[0];

// Проверка входящего токена авторизации
if ($input["token"] == NULL || $input["token"] == 'undefined') {
    http_response_code(403);
    $result["error"]["code"] = 403;
    $result["error"]["message"] = "Unauthenticated";
    echo json_encode($result);
    exit;
} else {
    $token = $input["token"];
}

// Получение данных о проекте
$projectData = json_decode(get_bearer($token, "https://api.smartsender.com/v1/me"), true);
if ($projectData["error"]["code"] > 222) {
    http_response_code($projectData["error"]["code"]);
    echo json_encode($projectData);
    exit;
}

// Проверка наличия всех необходимых входящих данных
if ($input["message"] == NULL || $input["message"] == 'undefined') {
    $result["error"]["code"] = 422;
    $result["error"]["description"]["message"][] = "Message is missing";
    $result["error"]["message"] = "Unprocessable entity";
}
if ($input["emails"] == NULL || $input["emails"] == 'undefined') {
    $result["error"]["code"] = 422;
    $result["error"]["description"]["emails"][] = "Emails is missing";
    $result["error"]["message"] = "Unprocessable entity";
}
if ($result["error"]["code"] == 422) {
    http_response_code(422);
    echo json_encode($result);
    exit;
}

// Получение почтовых ящиков операоров проекта
$pages = 2;
for ($page = 1; $page <= $pages; $page++) {
    $operatorsData = json_decode(get_bearer($token, "https://api.smartsender.com/v1/operators?page=".$page."&limitation=20"), true);
    $pages = $operatorsData["cursor"]["pages"];
    foreach ($operatorsData["collection"] as $operator) {
        $operators[$operator["info"]["email"]]["email"] = $operator["info"]["email"];
        $operators[$operator["info"]["email"]]["name"] = $operator["info"]["fullName"];
    }
}

if (is_array($input["emails"]) === true) {
    foreach($input["emails"] as $email) {
        $sender[$email] = $email;
    }
} else {
    $sender[$input["emails"]] = $input["emails"];
}

// Сверка почтовых ящиков
$sendTo = array_intersect_key($operators, $sender);
$sendError = array_diff_key($sender, $operators);
if ($sendError != NULL) {
	// Не будет отправлено на почтовые ящики, которых нет среди операторов проекта
    $resultSendError = "Уведомление не отправленно на email: ".implode(", ", $sendError)." так как этих адресов нет среди операторов проекта";
}

// Подготовка писма для отправки
$header = "From: Smart Sender notifier <noreply@smartsender.com>\r\n"
    ."Content-type: text/html; charset=utf-8\r\n"
    ."X-Mailer: PHP mail script by \"Mufik Soft\"";
$body = 
str_ireplace("{logoPhoto}", $url."/logo.png", str_ireplace("{syncPhoto}", $url."/sync.png", str_ireplace("{projectPhoto}", $projectData["photo"], str_ireplace("{project}", $projectData["name"], str_ireplace("{body}", str_replace("\n", "<br>\n", str_replace("\r", "", $input["message"])), file_get_contents('mail.html'))))));

// отправка писем на почтовые ящики из входящего масива, которые есть среди операторов проекта
foreach ($sendTo as $send) {
    $mail = mail( $send["email"], mb_encode_mimeheader ("Smart Sender. Уведомление из проекта \"".$projectData["name"]."\"", 'utf-8'), $body, $header);
    if ($mail == true) {
        $resultSend[] = "Уведомление оператору ".$send["name"]." успешно отправленно";
    } else {
        $resultSend[] = "Ошибка отправки уведомления оператору ".$send["name"];
    }
}

// проверка и подготовка масива ответа
if (is_array($resultSend) === true) {
    $result["state"] = true;
    $result["send"] = $resultSend;
} else {
    $result["state"] = false;
}
if ($resultSendError != NULL) {
    $result["sendError"] = $resultSendError;
}

// Ответ
echo json_encode($result);
