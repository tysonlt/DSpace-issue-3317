<?php

class DSpaceRest {

    private const XSRF_HEADER = 'DSPACE-XSRF-TOKEN';
    private const AUTH_HEADER = 'Authorization: Bearer';

    private $api_root;
    private $bearer_token;
    private $csrf_token;
    private $username;
    private $password;

    public function __construct($api_root, $username, $password) {
        $this->api_root = rtrim($api_root. '/');
        $this->username = $username;
        $this->password = $password;
    }

    public function get_all_items($key_by = 'id', $page_size = 100) : array {
        $result = [];
        $page = 0;
        $has_more = true;
        $found = 0;
        $total = 0;

        echo "Fetching all DSpace items:\n";
        while ($has_more) {
            $found = $this->get_items_by_page($page++, $result, $key_by, $page_size);
            $total += $found;
            echo "page $page: found $found\n";
            if ($found == 0) {
                $has_more = false;
            }
        }
        echo " done, loaded $total/". count($result) ." items.\n";
        return $result;
    }

    public function get_items_by_page($page, array &$result, $key_by = 'id', $size=100) : int {
        $count = 0;
        $response = $this->request("/api/core/item?page=$page&size=$size");
        foreach ($response['_embedded']['items'] as $item) {
            $key = $item[$key_by];
            if (array_key_exists($key, $result)) {
                throw new Exception("*** DUPLICATE KEY $key_by:$key\n");
            }
            $result[$key] = $item;
            $count++;
        }
        return $count;
    }

    private function login() {
        $auth_request = sprintf('/api/authn/login?user=%s&password=%s', rawurlencode($this->username), rawurlencode($this->password));
        return $this->_request($auth_request, 'POST', [], false);
    }

    public function request($uri, $method='GET', $data=[], $file=null, array $uri_list=[]) {

        $response = null;

        try {
            
            $response = $this->_request($uri, $method, $data, $file, $uri_list);

        } catch (DSpaceHttpStatusException $e) {

            try {
                
                $this->login();
                $response = $this->_request($uri, $method, $data, $file, $uri_list);

            } catch (DSpaceHttpStatusException $e) {
                if ($data = json_decode($e->response)) {
                    throw new Exception("DSpace said: ". $data->message);
                }
                throw new Exception("Couldn't connect to DSpace, perhaps your credentials are wrong.");
            }

        }

        return $response;

    }

    private function _request($uri, $method='GET', $data=[], $file=null, array $uri_list=[]) {

        if (false === strpos($uri, '://')) {
            $endpoint = rtrim($this->api_root, '/') .'/'. ltrim($uri, '/');
        } else {
            $endpoint = $uri;
        }
        $ch = curl_init($endpoint);

        $headers = [];
        if (!empty($this->bearer_token)) {
            $headers[] = sprintf('%s %s', self::AUTH_HEADER, $this->bearer_token);
        }
        if (!empty($this->csrf_token)) {
            $headers[] = "X-XSRF-TOKEN: ". $this->csrf_token;
            curl_setopt($ch, CURLOPT_COOKIE, "DSPACE-XSRF-COOKIE=". $this->csrf_token);
        }

        if ($file) {
            $headers[] = "Content-Type: multipart/form-data";
            $post = ['file' => $file];
            if (!empty($data)) {
                $post['properties'] = json_encode($data);
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post);

        } else if (!empty($uri_list)) {
            $headers[] = "Content-Type: text/uri-list";
            curl_setopt($ch, CURLOPT_POSTFIELDS, join("\n", $uri_list));
        } else if (!empty($data)) {
            $headers[] = "Content-Type: application/json";
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
        
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10); 
        curl_setopt($ch, CURLOPT_TIMEOUT, 30); 
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, [$this, 'process_curl_header']);

        $response = curl_exec($ch); 
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
       
        curl_close($ch);

        if ($status >= 400) {
            throw new DSpaceHttpStatusException($status, $response);
        }

        return json_decode($response, true);

    }

    private function process_curl_header($ch, $header) {

        if (false !== strpos($header, self::XSRF_HEADER)) {
            $arr = explode(':', $header);
            $this->csrf_token = trim($arr[1]);
        } else if (self::AUTH_HEADER == substr($header, 0, strlen(self::AUTH_HEADER))) {
            $this->bearer_token = trim(substr($header, strlen(self::AUTH_HEADER)));
        }

        return strlen($header);
    }

}

class DSpaceHttpStatusException extends Exception {
    public $response;
    public function __construct($status, $response) {
        parent::__construct("HTTP STATUS: $status", $status);
        $this->response = $response;
    }
}