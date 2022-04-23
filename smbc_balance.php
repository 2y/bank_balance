<?php
#$smbc    = new SMBCBalance('!ID!', '!PASSWORD!');
$balance = $smbc->getBalance();
var_dump($balance);
#$history = $rakuten->getHistory();
#var_dump($history);

class SMBCBalance {
    const URL_RAND  = 'https://e-biz.smbc.co.jp/core/exec/servlet/ACH99OMCL_SKBCNTL';
    const URL_LOGIN = 'https://e-biz.smbc.co.jp/core/exec/servlet/ACH99OMCL_WEBCNTL';

    private $login_id     = null;
    private $login_pass   = null;
    private $cookie       = null;
    private $jsp_id       = null;
    private $jsf_sequence = null;
    private $balance      = null;
    private $table        = [];

    public function __construct($id, $pass) {
        $this->login_id   = $id;
        $this->login_pass = $pass;
        $this->cookie     = tempnam(sys_get_temp_dir(),'cookie_smbc');
    }
    private function __destruct() {
        unlink($this->cookie);
    }
    public function getHistory() {
        $this->login();
        $this->auth();
        $this->mypage();
        return $this->history();
    }
    public function getBalance() {
        $this->login();
        $this->auth();
        $this->mypage();
        return $this->balance;
    }

    private function loadURL($url, $post = []) {
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL,            $url);
        curl_setopt($curl, CURLOPT_USERAGENT,      'Mozilla/5.0 (compatible; MSIE 10.0; Windows NT 6.2; rksl; Trident/6.0)'); 
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($curl, CURLOPT_COOKIEJAR,      $this->cookie); 
        curl_setopt($curl, CURLOPT_COOKIEFILE,     $this->cookie); 
        if ($post) {
            curl_setopt($curl, CURLOPT_POST,       TRUE);
            curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($post, '', '&'));
            var_dump($post);
        }
        curl_setopt($curl, CURLINFO_HEADER_OUT, true);
        $html = curl_exec($curl);
        $info = curl_getinfo($curl);
        var_dump($info);
        curl_close($curl);
        return $html;
    }
    private function login() {
        $random_num = mt_rand(0, 999);
        $table      = $this->loadURL(self::URL_RAND, array(
            'UkeID'            => 'ACHE9H001',
            'action'           => 'action3',
            'refreshRandomNum' => $random_num,
        ));
        if (strlen($table) != 1263) {
            throw new Exception('Failed to get login table');
        }
        $table = explode('=', $table);
        if (!isset($table[2])) {
            throw new Exception('Failed to get login table');
        }
        $this->table = explode(',', $table[2]);
        $pick = '';
        for ($i = 0; $i < 10; $i++) {
            $pick .= $this->table[62 * $i + 0];
            $pick .= $this->table[62 * $i + 30];
            $pick .= $this->table[62 * $i + 61];
        }
        $html = $this->loadUrl(self::URL_LOGIN, array(
            'User'                  => $this->login_id,
            'Pwd1'                  => $this->getMixValue($random_num, $this->login_pass),
            'hid1'                  => '',
            'UkeID'                 => 'ACHE9H001', 
            '_W_WebRtn'             => '0',
            '_W_OthWin'             => '0',
            'APNextScrID'           => 'ACH999002',
            'NinsyoKbn'             => '01',
            'ViewID'                => '',
            'ActionCtlFlg'          => '1',
            '_W_SoftKeyBoardFlag'   => '1',
            'pickTable'             => $pick,
            'to_ebiz_param'         => '',
            'G_Syubetsu'            => '0',
        ));
        var_dump($html);
        exit;
    }
    private function getMixValue($random_num, $password){
        $ret       = '';
        $table     = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        $table1pos = substr($random_num, 0, 1);
        $table2pos = substr($random_num, 1, 1);
        $table3pos = substr($random_num, 2, 1);
        for ($i = 0; $i < strlen($password); $i++){
            $temp  = $password[$i];
            $index = strpos($table, $temp);
            $temp  = $this->table[62 * $table1pos + $index];
            $index = strpos($table, $temp);
            $temp  = $this->table[62 * $table2pos + $index];
            $index = strpos($table, $temp);
            $temp  = $this->table[62 * $table3pos + $index];
            $ret  .= $temp;
        }
        return $ret;
    }
    private function auth() {
        $html = $this->loadURL(self::RAKUTEN_URL_AUTH, array(
            'LOGIN:LOGIN_PASSWORD'  => $this->login_pass,
            'LOGIN:USER_ID'         => $this->login_id,
            $this->jsp_id           => '',
            'LOGIN:_link_hidden_'   => '',
            'LOGIN_SUBMIT'          => 1,
            'jsf_sequence'          => 1,
        ));
        if (!strpos($html, 'しばらくお待ちください')) {
            throw new Exception('Faild to authenticate');
        }
    }
    private function mypage() {
        $html = $this->loadURL(self::RAKUTEN_URL_MYPAGE);
        if (!strpos($html, '普通預金残高')) {
            throw new Exception('Failed to load mypage');
        }
        if (!preg_match('$<font class="text18bold">([0-9,]+)</font>$', $html, $match)) {
            throw new Exception('Failed to get balance');
        }
        $balance       = $match[1];
        $balance       = str_replace(',', '', $balance);
        $this->balance = (int)$balance;
        if (!preg_match('$id="(FORM:_idJsp[0-9]+)" class="smedium">入出金明細</a>$', $html, $match)) {
            throw new Exception('Faild to get jsp id');
        }
        $this->jsp_id = $match[1];
        if (!preg_match('$<input type="hidden" name="jsf_sequence" value="([0-9]+)" />$', $html, $match)) {
            throw new Exception('Faild to get jsf sequence');
        }
        $this->jsf_sequence = $match[1];
    }
    private function history() {
        $html = $this->loadURL(self::RAKUTEN_URL_HISOTRY, array(
            'FORM:_link_hidden_'=> $this->jsp_id,
            'FORM_SUBMIT'       => 1,
            'jsf_sequence'      => $this->jsf_sequence,
        ));
        if (!strpos($html, '入出金明細')) {
            throw new Exception('Faild to load history page');
        }
        $history = array();
        preg_match_all('$<tr class="td0[1,2]line">\n<td width="120" class="td0[1,2]">\n' .
            '<div class="innercell">\n([0-9/]+)</div>\n' .
            '</td>\n<td width="370" class="td0[1,2]">\n<div class="innercell">\n(.+)</div>\n' .
            '</td>\n<td width="120" class="td0[1,2]right">\n<div class="innercell">\n([0-9,]+)</div>\n' .
            '</td>\n<td width="100" class="td0[1,2]right">\n<div class="innercell">\n([0-9,]+)</div>\n</td>\n</tr>$', $html, $match);
        if (isset($match[3])) {
            foreach ($match[3] as $key => $price) {
                $price  = (int)str_replace(',', '', $price);
                $detail = mb_convert_kana($match[2][$key], 'k', 'UTF-8');
                $detail = mb_convert_kana($detail, 'KVa', 'UTF-8');
                $detail = str_replace(['-', '　', '&nbsp;'], ['ー', ' ', ' '], $detail);
                $history[] = array(
                    'date'   => $match[1][$key],
                    'detail' => $detail,
                    'price'  => $price,
                );
            }
        }
        return $history;
    }
}
