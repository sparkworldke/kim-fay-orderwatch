<?php

class AccumaticaDBTables
{
    private $wpdb;
    private $accumatica_db_version;
    private $auth_access;
    private $transactions;
    private $charset_collate;

    public function __construct()
    {
        global $wpdb;

        $this->wpdb = $wpdb;
        $this->accumatica_db_version = '1.0';
        $this->auth_access = $this->wpdb->prefix . 'accumatica_auth_access';
        $this->transactions = $this->wpdb->prefix . 'accumatica_transactions';
        $this->charset_collate = $this->wpdb->get_charset_collate();
    }

    public function createAuthenticationAccessTable()
    {
        $sql = "CREATE TABLE $this->auth_access (
			id mediumint(9) NOT NULL AUTO_INCREMENT,            
			access_token varchar(100) NULL,
			expire_in datetime NULL,					
			created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
			updated_at TIMESTAMP NULL DEFAULT NULL,			
			PRIMARY KEY (id)
		) $this->charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        add_option('accumatica_db_version', $this->accumatica_db_version);
    }

    public function createTransactionTable()
    {
        $sql = "CREATE TABLE $this->transactions (
			id mediumint(9) NOT NULL AUTO_INCREMENT,
			accumatica_order_id varchar(200) NULL,
			accumatica_payment_id varchar(200) NULL,
			accumatica_customer_id varchar(200) NULL,
			wc_order_id varchar(200) NULL,
			payment_status tinyint(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
			updated_at TIMESTAMP NULL DEFAULT NULL,				
			PRIMARY KEY (id)
		) $this->charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        add_option('accumatica_db_version', $this->accumatica_db_version);
    }
}
