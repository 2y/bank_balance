<?php
#$rakuten = new RakutenBalance('!ID!', '!PASSWORD!');
#$balance = $rakuten->getBalance();
#var_dump($balance);
#$history = $rakuten->getHistory();
#var_dump($history);

class RakutenBalance {
    const RAKUTEN_URL_LOGIN   = 'https://fes.rakuten-bank.co.jp/MS/main/RbS?COMMAND=LOGIN&&CurrentPageID=START_ACCOUNT_PLUS';
    const RAKUTEN_URL_AUTH    = 'https://fes.rakuten-bank.co.jp/MS/main/fcs/rb/fes/jsp/mainservice/AccountPlus/Security/Login/Login.jsp';
    const RAKUTEN_URL_ACCOUNT = 'https://fes.rakuten-bank.co.jp/MS/main/fcs/rb/fes/jsp/mainservice/AccountPlus/Menu/AccountSelected/AccountSelected.jsp';
    const RAKUTEN_URL_MYPAGE  = 'https://fes.rakuten-bank.co.jp/MS/main/gns?COMMAND=BALANCE_INQUIRY_START&&CurrentPageID=HEADER_FOOTER_LINK';
    const RAKUTEN_URL_HISOTRY = 'https://fes.rakuten-bank.co.jp/MS/main/fcs/rb/fes/jsp/mainservice/AccountPlus/Menu/ServiceSelected/CsServiceSelectedMenu.jsp';

    private $login_id     = null;
    private $login_pass   = null;
    private $cookie       = null;
    private $jsp_id       = null;
    private $jsf_sequence = null;
    private $balance      = null;

    public function __construct($group, $id, $pass) {
        $this->login_group = $group;
        $this->login_id    = $id;
        $this->login_pass  = $pass;
    }
    public function getHistory() {
        $this->login();
        $this->auth();
        $this->account();
        //$this->mypage();
        return $this->history();
    }
    public function getBalance() {
        $this->login();
        $this->auth();
        $this->mypage();
        return $this->balance;
    }

    private function loadURL($url, $params = []) {
        $stream_context = [
            'http' => array(
                'method' => 'GET',
                'header' => 'User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10.10; rv:39.0) Gecko/20100101 Firefox/39.0',
            ),
        ];
        if ($params) {
            $params = http_build_query($params, '', '&');
            $stream_context = array('http' =>
                array(
                    'method'  => 'POST',
                    'header'  => implode("\r\n", array(
                        'User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10.10; rv:39.0) Gecko/20100101 Firefox/39.0',
                        'Content-Type: application/x-www-form-urlencoded',
                        'Content-Length: ' . strlen($params),
                        'Cookie: ' . $this->cookie,
                    )),
                    'content' => $params,
                )
            );
        } elseif ($this->cookie) {
            $stream_context = array('http' =>
                array(
                    'method'  => 'GET',
                    'header'  => implode("\r\n", array(
                        'User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10.10; rv:39.0) Gecko/20100101 Firefox/39.0',
                        'Cookie: ' . $this->cookie,
                    )),
                )
            );
        }
        $context  = stream_context_create($stream_context);
        $html     = file_get_contents($url, false, $context);
        $html     = mb_convert_encoding($html, 'UTF-8', 'SJIS-win');
        $this->parseCookie($http_response_header);
        return $html;
    }
    private function parseCookie($response) {
        foreach ($response as $res) {
            if (strpos($res, 'Set-Cookie: FESSessionID=') === 0) {
                $cookie = substr($res, 12);
                $cookie = substr($cookie, 0, strpos($cookie, ';'));
                $this->cookie = $cookie;
                return;
            }
        }
    }
    private function login() {
        $html = $this->loadURL(self::RAKUTEN_URL_LOGIN);
        if (!isset($this->cookie)) {
            throw new Exception('Failed to get Cookie');
        }
        if (!preg_match('$<input id="(LOGIN:_idJsp[0-9]+)"$', $html, $match)) {
            throw new Exception('Faild to load login page');
        }
        $this->jsp_id = $match[1];
    }
    private function auth() {
        $html = $this->loadURL(self::RAKUTEN_URL_AUTH, array(
            'LOGIN:ACCOUNTGROUP_ID' => $this->login_group,
            'LOGIN:LOGIN_PASSWORD'  => $this->login_pass,
            'LOGIN:USER_ID'         => $this->login_id,
            $this->jsp_id           => '',
            'LOGIN:_link_hidden_'   => '',
            'LOGIN_SUBMIT'          => 1,
            'jsf_sequence'          => 1,
        ));
        if (!strpos($html, '利用可能口座')) {
            throw new Exception('Faild to authenticate');
        }
    }
    private function account() {
        $html = $this->loadURL(self::RAKUTEN_URL_ACCOUNT, array(
            'ACCOUNT_SELECTED_FORM:_idJsp98:0:_idJsp121' => '',
            'ACCOUNT_SELECTED_FORM:_link_hidden_'        => '',
            'ACCOUNT_SELECTED_FORM_SUBMIT'               => 1,
            'jsf_sequence'                               => $this->jsf_sequence,
        ));
        if (!strpos($html, 'サービス選択')) {
            throw new Exception('Faild to authenticate2');
        }
        if (!preg_match('$id="(FORM:_idJsp[0-9]+)">入出金明細</a>$', $html, $match)) {
            throw new Exception('Faild to get jsp id');
        }
        $this->jsp_id = $match[1];
        if (!preg_match('$<input type="hidden" name="jsf_sequence" value="([0-9]+)" />$', $html, $match)) {
            throw new Exception('Faild to get jsf sequence');
        }
        $this->jsf_sequence = $match[1];
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
