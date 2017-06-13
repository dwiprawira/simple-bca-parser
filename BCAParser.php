<?php

require 'SimpleHTMLDom.php';

class BCAParser
{
    private $ch;
    private $post_time;
    private $conf;
	
	private $cookiejar_file;
	private $cookie_file;

    public $accountNo;
    public $accountOwner;
    public $transactions;
    public $balance;
    public $errorMessage;

    public function __construct($username, $password)
    {
        $this->conf = $this->getConfig();
		$this->cookie_file = $this->conf['path'] . '/cookie' . uniqid();
		$this->cookiejar_file = $this->conf['path'] . '/cookiejar' . uniqid();
        $this->loadData($username, $password);
		if(file_exists($this->cookie_file)){
            unlink($this->cookie_file);
        }
        if(file_exists($this->cookiejar_file)){
            unlink($this->cookiejar_file);
        }
    }

    private function getConfig()
    {
        return [
            'path' => getcwd(),
            'ip' => self::getIPAddress()
        ];
    }

    private static function getIPAddress()
    {
        return file_get_contents('https://api.ipify.org');
    }


    private function curlexec()
    {
        curl_setopt($this->ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($this->ch, CURLOPT_SSL_VERIFYPEER, false);
        if (curl_error($this->ch)) {
            echo 'error:' . curl_error($this->ch);
        }
        return curl_exec($this->ch);
    }


    private function login($username, $password, &$errMessage)
    {
        $this->ch = curl_init();
        curl_setopt($this->ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/59.0.3071.86 Safari/537.36');
        curl_setopt($this->ch, CURLOPT_URL, 'https://ibank.klikbca.com/login.jsp');
        curl_setopt($this->ch, CURLOPT_COOKIEFILE, $this->cookie_file);
        curl_setopt($this->ch, CURLOPT_COOKIEJAR, $this->cookiejar_file);
        $this->curlexec();
        $params = implode('&', array('value(user_id)=' . $username, 'value(pswd)=' . $password, 'value(Submit)=LOGIN', 'value(actions)=login', 'value(user_ip)=' . $this->conf['ip'], 'user_ip=' . $this->conf['ip'], 'value(mobile)=true', 'mobile=true'));
        curl_setopt($this->ch, CURLOPT_URL, 'https://ibank.klikbca.com/authentication.do');
        curl_setopt($this->ch, CURLOPT_REFERER, 'https://ibank.klikbca.com/login.jsp');
        curl_setopt($this->ch, CURLOPT_POSTFIELDS, $params);
        curl_setopt($this->ch, CURLOPT_POST, 1);
        $return = $this->curlexec();
        $err = $this->get_string_between($return, "var err='", "'");
        $err = str_replace('\n', '<br>', $err);
		
        if ($err) {
			
            $errMessage = strip_tags($err);
            return false;
        }
        return true;
    }

    private function logout()
    {
        curl_setopt($this->ch, CURLOPT_URL, 'https://ibank.klikbca.com/authentication.do?value(actions)=logout');
        curl_setopt($this->ch, CURLOPT_REFERER, 'https://ibank.klikbca.com/authentication.do?value(actions)=menu');
        $this->curlexec();
        curl_close($this->ch);
    }

    private function loadData($username, $password)
    {
        if (!$this->login($username, $password, $this->errorMessage)) {
            curl_close($this->ch);
            return;
        }
		
        $start = new \DateTime();
        $this->post_time['end']['y'] = $start->format("Y");
        $this->post_time['end']['m'] = $start->format("m");
        $this->post_time['end']['d'] = $start->format("d");

        $start->modify('-30 days');
        $this->post_time['start']['y'] = $start->format("Y");
        $this->post_time['start']['m'] = $start->format("m");
        $this->post_time['start']['d'] = $start->format("d");

        //TRANSACTIONS
        curl_setopt($this->ch, CURLOPT_URL, 'https://ibank.klikbca.com/accountstmt.do?value(actions)=menu');
        curl_setopt($this->ch, CURLOPT_REFERER, 'https://ibank.klikbca.com/authentication.do');
        $this->curlexec();
        curl_setopt($this->ch, CURLOPT_URL, 'https://ibank.klikbca.com/accountstmt.do?value(actions)=acct_stmt');
        curl_setopt($this->ch, CURLOPT_REFERER, 'https://ibank.klikbca.com/accountstmt.do?value(actions)=menu');
        $this->curlexec();
        $params = implode('&', array('r1=1', 'value(D1)=0', 'value(startDt)=' . $this->post_time['start']['d'], 'value(startMt)=' . $this->post_time['start']['m'], 'value(startYr)=' . $this->post_time['start']['y'], 'value(endDt)=' . $this->post_time['end']['d'], 'value(endMt)=' . $this->post_time['end']['m'], 'value(endYr)=' . $this->post_time['end']['y']));
        curl_setopt($this->ch, CURLOPT_URL, 'https://ibank.klikbca.com/accountstmt.do?value(actions)=acctstmtview');
        curl_setopt($this->ch, CURLOPT_REFERER, 'https://ibank.klikbca.com/accountstmt.do?value(actions)=acct_stmt');
        curl_setopt($this->ch, CURLOPT_POSTFIELDS, $params);
        curl_setopt($this->ch, CURLOPT_POST, 1);
        $src = $this->curlexec();

        //BALANCES
        curl_setopt($this->ch, CURLOPT_URL, 'https://ibank.klikbca.com/accountstmt.do?value(actions)=menu');
        curl_setopt($this->ch, CURLOPT_REFERER, 'https://ibank.klikbca.com/authentication.do');
        $this->curlexec();
        curl_setopt($this->ch, CURLOPT_URL, 'https://ibank.klikbca.com/balanceinquiry.do');
        curl_setopt($this->ch, CURLOPT_REFERER, 'https://ibank.klikbca.com/accountstmt.do?value(actions)=menu');
        $balanceSrc = $this->curlexec();

        $this->logout();

        $trans = $this->getTransactions($src);
        if ($trans) {
            $this->transactions = $trans;
        } else {
            $this->errorMessage = "Failed to get transactions data";
            return;
        }

        $balance = $this->getBalance($balanceSrc);
        if ($balance) {
            $this->balance = $balance;
        } else {
            $this->errorMessage = "Failed to get balance";
            return;
        }

        $accountNo = $this->getAccountNo($src);
        if ($accountNo) {
            $this->accountNo = $accountNo;
        } else {
            $this->errorMessage = "Failed to account number";
            return;
        }

        $accountOwner = $this->getAccountOwner($src);
        if ($accountOwner) {
            $this->accountOwner = $accountOwner;
        } else {
            $this->errorMessage = "Failed to account owner";
            return;
        }

    }

    private function getTransactions($src)
    {
        $htmldom = SimpleHTMLDom::str_get_html($src);
        $table = $htmldom->find('table', 4);
        $data = [];
        foreach ($table->find('tr') as $key => $row) {
			if ($key > 0) {
                $cols = $row->find('td');
                if (count($cols) >= 4) {
                    $date = trim($cols[0]->plaintext);
                    $desc = trim($cols[1]->plaintext);
                    $amount = trim($cols[3]->plaintext);
                    $type = trim($cols[4]->plaintext);

                    if (stristr($date, 'pend')) {
                        $date = 'PEND';
                    } else {
                        $exp = explode("/", $date);
                        $month = end($exp);
                        $day = $exp[0];
                        if (date("m") <> $month && $month == "12") {
                            $year = (int)date("Y") - 1;
                        } else {
                            $year = date("Y");
                        }
                        $date = date('Y-m-d', strtotime("$year-$month-$day"));
                    }

                    $newData['recordDate'] = $date;
                    $newData['transactionDate'] = $this->getTransactionDate($date, $desc);
                    //$newData['detail'] = $desc;

                    $transactionCategory = explode("\n", $desc);

                    if ($newData['recordDate'] != "PEND") {
                        $day = date("d", strtotime($newData['transactionDate']));
                        $month = date("m", strtotime($newData['transactionDate']));
                        $category = str_replace("$day/$month", "", $transactionCategory[0]);
                        $newData['category'] = trim($category);
                        unset($transactionCategory[0]);
                        $newData['detail'] = implode("\n", $transactionCategory);
                    } else {
                        $newData['category'] = trim($transactionCategory[0]);
                        unset($transactionCategory[0]);
                        $newData['detail'] = implode("\n", $transactionCategory);
                    }

                    $newData['type'] = $type;
                    if ($type == "DB") {
                        $newData['amountDB'] = str_replace(",", "", $amount);
                        $newData['amountCR'] = "0";
                    } else {
                        $newData['amountCR'] = str_replace(",", "", $amount);
                        $newData['amountDB'] = "0";
                    }
                    $newData['ordinal'] = $key;
					$data[] = $newData;
                }
            }
        }
        return $data;
    }

    private function getBalance($src)
    {
        $htmldom = SimpleHTMLDom::str_get_html($src);
        $table = $htmldom->find('table', 2);
        $tr = $table->find('tr', 1);
        $td = $tr->find('td', 3);
        $amount = trim($td->plaintext);
        $amount = str_replace(",", "", $amount);

        if (is_numeric($amount)) {
            return $amount;
        } else {
            return false;
        }
    }

    private function getAccountNo($src)
    {
        $htmldom = SimpleHTMLDom::str_get_html($src);
        $table = $htmldom->find('table', 2);
        $tr = $table->find('tr', 2);
        $td = $tr->find('td', 2);
        $name = trim($td->plaintext);

        if (!empty($name)) {
            return $name;
        } else {
            return false;
        }
    }

    private function getAccountOwner($src)
    {
        $htmldom = SimpleHTMLDom::str_get_html($src);
        $table = $htmldom->find('table', 2);
        $tr = $table->find('tr', 3);
        $td = $tr->find('td', 2);
        $name = trim($td->plaintext);

        if (!empty($name)) {
            return $name;
        } else {
            return false;
        }
    }

    private function getTransactionDate($date, $desc)
    {
        if ($this->startsWith($desc, "TRSF E-BANKING")) {
            $desc = explode("\n", $desc);
            $transaction_date = $desc[1];
            $transaction_date = substr($transaction_date, 0, 5);
            $contain_slash = false;

            if ($this->endsWith($transaction_date, "/")) {
                $transaction_date = substr($transaction_date, 0, 4);
            }

            if (strpos($transaction_date, "/") !== false) {
                $contain_slash = true;
                $transaction_date = str_replace("/", "", $transaction_date);
            }

            if (is_numeric($transaction_date)) {
                if ($contain_slash) {
                    $day = substr($transaction_date, 2, 2);
                    $month = substr($transaction_date, 0, 2);
                } else {
                    $day = substr($transaction_date, 0, 2);
                    $month = substr($transaction_date, 2, 2);
                }
            } else {
                return $date;
            }

        } else if ($this->startsWith($desc, "TARIKAN ATM ")) {
            $desc = str_replace("TARIKAN ATM ", "", $desc);
            $desc = trim($desc);
			$expDesc = explode("/", $desc);
            if (count($expDesc) != 2) {
                return $date;
            } else {
                if (strlen($expDesc[0]) == 2 && strlen($expDesc[1]) == 2) {
                    $day = $expDesc[0];
                    $month = $expDesc[1];

                } else {
                    return $date;
                }
            }
        } else {
            $desc = explode("\n", $desc);
            $expDesc = explode("/", $desc[0]);

            if (count($expDesc) < 2) {
                return $date;
            } else {
                if (strlen($expDesc[0]) < 2 || strlen($expDesc[1] < 2)) {
                    return $date;
                } else {
                    $day = substr($expDesc[0], -2);
                    $month = substr($expDesc[1], 0, 2);
                }
            }
        }

        if (!(is_numeric($day) && is_numeric($month))) {
            return $date;
        } else {
            if (date("m") <> $month && $month == "12") {
                $year = (int)date("Y") - 1;
            } else {
                $year = date("Y");
            }
            if (checkdate($month, $day, $year)) {
                $dateStr = "$year-$month-$day";
                return date("Y-m-d", strtotime($dateStr));
            } else {
                return $date;
            }
        }
    }

    private function startsWith($haystack, $needle)
    {
        $length = strlen($needle);
        return (substr($haystack, 0, $length) === $needle);
    }

    private function endsWith($haystack, $needle)
    {
        $length = strlen($needle);
        if ($length == 0) {
            return true;
        }

        return (substr($haystack, -$length) === $needle);
    }

    private function get_string_between($string, $start, $end)
    {
        $string = ' ' . $string;
        $ini = strpos($string, $start);
        if ($ini == 0) return null;
        $ini += strlen($start);
        $len = strpos($string, $end, $ini) - $ini;
        return substr($string, $ini, $len);
    }

}