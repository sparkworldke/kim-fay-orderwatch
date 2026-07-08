<?php

require_once __DIR__ . '/class-helper.php';

final class TableOperations
{
    private $wpdb;
    private $auth_access;
    private $transactions;

    public function __construct()
    {
        global $wpdb;

        $this->wpdb = $wpdb;
        $this->auth_access = $this->wpdb->prefix . 'accumatica_auth_access';
        $this->transactions = $this->wpdb->prefix . 'accumatica_transactions';
    }

    public function saveToken($token)
    {
        $nextExpiry = Helper::timerIncrement();

        $this->wpdb->query(
            $this->wpdb->prepare(
                "INSERT INTO $this->auth_access(
                    access_token, expire_in                    
                )VALUES (%s,%s)",
                array($token, $nextExpiry)
            )
        );

        return $this->fetchTokenById($this->wpdb->insert_id);
    }

    public function updateToken($token)
    {
        $nextExpiry = Helper::timerIncrement();

        return $this->wpdb->query(
            $this->wpdb->prepare(
                "UPDATE $this->auth_access 
                SET access_token = %s, expire_in = %s
				WHERE id = %d ",
                array($token, $nextExpiry, 1)
            )
        );
    }

    public function fetchTokenById($id = 1)
    {
        $stmt = $this->wpdb->prepare(
            "SELECT id, access_token, expire_in
                FROM $this->auth_access WHERE id = %d
            ",
            array($id)
        );

        return  $this->wpdb->get_row($stmt);
    }

    public function fetchTransactionById($id)
    {
        $stmt = $this->wpdb->prepare(
            "SELECT id, accumatica_order_id, accumatica_payment_id, wc_order_id, payment_status
                FROM $this->transactions WHERE id = %d 
            ",
            array($id)
        );

        return  $this->wpdb->get_row($stmt);
    }

    public function fetchTransactionByWcOrderId($id)
    {
        $stmt = $this->wpdb->prepare(
            "SELECT id, accumatica_order_id, accumatica_payment_id, accumatica_customer_id, wc_order_id, payment_status
                FROM $this->transactions
                WHERE wc_order_id = %d 
                ORDER BY id DESC
                LIMIT 1 
            ",
            array($id)
        );

        return  $this->wpdb->get_row($stmt);
    }


    public function createNewTransaction($accOrderId, $accPaymentId, $accCustomerId, $wcOrderId)
    {
        $this->wpdb->query(
            $this->wpdb->prepare(
                "INSERT INTO $this->transactions(
                    accumatica_order_id, accumatica_payment_id, accumatica_customer_id, wc_order_id                    
                )VALUES (%s,%s,%s,%s)",
                array($accOrderId, $accPaymentId, $accCustomerId, $wcOrderId)
            )
        );

        return $this->fetchTransactionById($this->wpdb->insert_id);
    }

    public function updateTransaction($id)
    {
        return $this->wpdb->query(
            $this->wpdb->prepare(
                "UPDATE $this->transactions 
                SET payment_status = %s
				WHERE wc_order_id = %d",
                array(1, $id)
            )
        );
    }
}
