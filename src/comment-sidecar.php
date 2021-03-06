<?php
include_once "common.php";

/**
 * HTTP endpoints
 * GET comment-sidecar.php?site=<site>&path=<path>
 *      get comments
 * POST comment-sidecar.php with comment JSON
 *      create a new comment
 */
function main() {
    $method = $_SERVER['REQUEST_METHOD'];
    header('Content-Type: application/json');
    setCORSHeader();
    $rateLimiter = new RateLimiter();
    try {
        switch ($method) {
            case 'GET': {
                echo getCommentsAsJson();
                break;
            }
            case 'POST': {
                $comment = json_decode(file_get_contents('php://input'),true);
                checkForSpam($comment);
                validatePostedComment($comment);
                $rateLimiter->checkIpAgainstRateLimit();
                $createdId = createComment($comment);
                $rateLimiter->insert_ip_entry();
                sendNotificationToAdminViaMail($comment);
                if (isset($comment["replyTo"])){
                    sendNotificationToParentAuthorViaMail($comment);
                }
                http_response_code(201);
                echo ' { "id" : '. $createdId .' } ';
                break;
            }
            case 'OPTIONS': {
                //preflight requests for CORS checks will appear, but the required headers have already been set.
                //this case is just for documentation
                break;
            }
        }
    } catch (Exception $ex) {
        if ($ex instanceof InvalidRequestException) {
            http_response_code(400);
            echo '{ "message" : "' . $ex->getMessage() . '" }';
        } else { //like PDOException
            http_response_code(500);
            echo '{ "message" : "' . $ex->getMessage() . '" }';
        }
    }
}

function setCORSHeader() {
    $http_origin = $_SERVER['HTTP_ORIGIN'];
    if (in_array($http_origin, ALLOWED_ACCESSING_SITES)) {
        header("Access-Control-Allow-Origin: $http_origin");
        header('Access-Control-Allow-Methods: GET, POST');
        header('Access-Control-Allow-Headers: Content-Type');
    }
}

function isInvalidReplyToId($ex){
    return strpos($ex->getMessage(), 'replyTo_refers_to_existing_id') !== false;
}

function getCommentsAsJson() {
    if (!isset($_GET['site']) or empty($_GET['site'])
        or !isset($_GET['path']) or empty($_GET['path'])) {
        throw new InvalidRequestException("Please submit both query parameters 'site' and 'path'");
    }
    $stmt = Database::getConnection()->prepare("SELECT id, author, content, email, reply_to, site, path, unix_timestamp(creation_date) as creationTimestamp FROM comments WHERE site = :site and path = :path ORDER BY creation_date desc;");
    $stmt->bindParam(":site", $_GET['site']);
    $stmt->bindParam(":path", $_GET['path']);
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $json = mapToJson($results);
    return $json;
}

const ROOT = "ROOT";

function mapToJson($results) {
    if ($results == null){
        return json_encode([]);
    }
    $replyToIdToCommentsMap = createReplyIdToCommentsMap($results);
    $rootComments = $replyToIdToCommentsMap[ROOT];
    nestRepliesIntoTheirParentComments($rootComments, $replyToIdToCommentsMap);
    return json_encode($rootComments);
}

function nestRepliesIntoTheirParentComments(&$comments, $replyToIdToCommentsMap) {
    //run over comments and see if there is an map entries for this id
    foreach ($comments as &$comment) {
        $id = $comment['id'];
        if (isset($replyToIdToCommentsMap[$id])) {
            $comment['replies'] = $replyToIdToCommentsMap[$id];
            nestRepliesIntoTheirParentComments($comment['replies'], $replyToIdToCommentsMap);
        }
    }
}

function createReplyIdToCommentsMap($results) {
    $replyToIdToCommentsMap = array(); // comment id -> comments having this id as replyTo
    foreach ($results as $result) {
        $replyToId = isset($result['reply_to']) ? $result['reply_to'] : ROOT;
        if (!isset($replyToIdToCommentsMap[$replyToId])) {
            $replyToIdToCommentsMap[$replyToId] = array();
        }
        $replyToIdToCommentsMap[$replyToId][] = array(
            'id' => $result['id'],
            'author' => $result['author'],
            'content' => $result['content'],
            'creationTimestamp' => $result['creationTimestamp']
        );
    }
    return $replyToIdToCommentsMap;
}

function createComment($comment) {
    try {
        $stmt = Database::getConnection()->prepare("INSERT INTO comments (author, email, content, reply_to, site, path, subscribed, unsubscribe_token) VALUES (:author, :email, :content, :reply_to, :site, :path, :subscribed, :unsubscribe_token);");
        $author = htmlspecialchars($comment["author"]);
        $content = htmlspecialchars($comment["content"]);
        $subscribed = (isset($comment["email"]) and !empty(trim($comment['email'])));
        $stmt->bindParam(':author', $author);
        $stmt->bindParam(':email', $comment["email"]); // optional. can be null
        $stmt->bindParam(':content', $content);
        $stmt->bindParam(':reply_to', $comment['replyTo']);
        $stmt->bindParam(':site', $comment["site"]);
        $stmt->bindParam(':path', $comment["path"]);
        $stmt->bindValue(':subscribed', $subscribed, PDO::PARAM_BOOL);
        $stmt->bindValue(':unsubscribe_token', generateRandomString(10));
        $stmt->execute();
        $createdId = Database::getConnection()->lastInsertId();
        return $createdId;
    } catch (PDOException $ex){
        if (isInvalidReplyToId($ex)) {
            throw new InvalidRequestException("The replyTo value '".$comment["replyTo"]."' refers to a not existing id.");
        }
        throw $ex;
    }
}

function generateRandomString($length = 10) {
    return substr(str_shuffle(str_repeat($x='0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ', ceil($length/strlen($x)) )),1,$length);
}

function checkForSpam($comment) {
    if (isset($comment['url']) and !empty(trim($comment['url']))) {
       throw new InvalidRequestException("");
    }
}

function validatePostedComment($comment){
    checkExistence($comment, 'author');
    checkExistence($comment, 'content');
    checkExistence($comment, 'site');
    checkExistence($comment, 'path');
    checkMaxLength($comment, 'author', 40);
    checkMaxLength($comment, 'email', 40);
    checkMaxLength($comment, 'site', 40);
    checkMaxLength($comment, 'path', 170);
}

function checkMaxLength($comment, $fieldName, $maxLength) {
    if (strlen($comment[$fieldName]) > $maxLength) {
        throw new InvalidRequestException("$fieldName value exceeds maximal length of " . $maxLength);
    }
}

function checkExistence($comment, $field) {
    if (!isset($comment[$field]) or empty(trim($comment[$field]))) {
        throw new InvalidRequestException("$field is missing, empty or blank");
    }
}

function sendNotificationToAdminViaMail($comment) {
    $author = $comment['author'];
    $path = $comment["path"];
    $site = $comment["site"];
    $commentUrl = createCommentUrl($comment);
    $message = "Site: $site\n";
    $message .= "Path: $path\n";
    $message .= "URL: $commentUrl\n";
    $message .= "Message: " . $comment["content"] . "\n";
    $subject = "Comment by $author on $path";
    sendMail(E_MAIL_FOR_NOTIFICATIONS, $comment['author'], $comment['email'], $message, $subject);
}

function sendNotificationToParentAuthorViaMail($new_comment){
    $parentComment = find_parent_author_email($new_comment["replyTo"]);
    if ($parentComment !== null) {
        $translations = readTranslations();
        $parentAuthor = $parentComment['author'];
        $author = $new_comment['author'];
        $unsubscribeUrl = BASE_URL . "unsubscribe.php?commentId=".$parentComment["id"]."&unsubscribeToken=".$parentComment["unsubscribe_token"];
        $commentUrl = createCommentUrl($new_comment);
        $subject = str_replace("{}", $author, $translations['subject']);
        $content = "Hi $parentAuthor,\n\n";
        $content .= $translations['introduction']."\n\n";
        $content .= $translations['author'].": $author\n";
        $content .= "URL: $commentUrl\n";
        $content .= $translations['message'].":\n";
        $content .= $new_comment["content"] . "\n\n";
        $content .= $translations['unsubscribeDescription']."\n".$unsubscribeUrl;
        sendMail($parentComment['email'], $new_comment['author'], "dontReply@dontReply.com", $content, $subject);
    }
}

function createCommentUrl($comment): string {
    $url = $comment['site'] . $comment['path'] . "#comment-sidecar";
    return $url;
}

function sendMail($toMail, $fromName, $fromEmail, $message, $subject){
    $from = (isset($fromEmail) and !empty($fromEmail)) ? "$fromName<${fromEmail}>" : "$fromName";
    $headers = "From: ${from}\n";
    $headers .= "Mime-Version: 1.0\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\n";
    $headers .= "Content-Transfer-Encoding: 8bit\n";
    $headers .= "X-Mailer: PHP ".phpversion();
    mail($toMail, $subject, $message, $headers);
}

function find_parent_author_email($parentCommentId) {
    $stmt = Database::getConnection()->prepare("SELECT * FROM comments WHERE id = :parent_comment_id AND subscribed = true");
    $stmt->bindParam(':parent_comment_id', $parentCommentId);
    $stmt->execute();
    if ($stmt->rowCount() == 0){
        return null;
    }
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return $results[0];
}

class RateLimiter {
    function checkIpAgainstRateLimit() {
        $ip = $_SERVER['REMOTE_ADDR'];
        $this->clean_up_outdated_ips();
        if ($this->ip_entry_exists($ip)) {
            throw new InvalidRequestException("You have exceeded the maximal number of comments within a time frame.");
        }
    }

    private function ip_entry_exists($ip) {
        $stmt = Database::getConnection()->prepare("SELECT count(ip) as ip_count FROM ip_addresses WHERE ip = :ip;");
        $stmt->bindParam(":ip", $ip);
        $stmt->execute();
        $count =  $stmt->fetchColumn();
        return $count == 1;
    }

    private function clean_up_outdated_ips() {
        $stmt = Database::getConnection()->prepare("DELETE FROM ip_addresses WHERE creation_date < ADDDATE(NOW(6), INTERVAL -:rateLimitThreshold SECOND);");
        $rateLimitThreshold = RATE_LIMIT_THRESHOLD_SECONDS;
        $stmt->bindParam(":rateLimitThreshold", $rateLimitThreshold);
        $stmt->execute();
    }

    public function insert_ip_entry() {
        $stmt = Database::getConnection()->prepare("INSERT INTO ip_addresses (ip) VALUES (:ip);");
        $stmt->bindParam(':ip', $_SERVER['REMOTE_ADDR']);
        $stmt->execute();
    }
}

class Database {
    private static $db;
    private $connection;

    private function __construct() {
        $this->connection = connect();
    }

    function __destruct() {
        $this->connection = null;
    }

    public static function getConnection() {
        if (self::$db == null) {
            self::$db = new Database();
        }
        return self::$db->connection;
    }
}

main();
