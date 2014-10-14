<?php
/*-------------------------------------------------------+
| SYSTOPIA Donation Receipts Extension                   |
| Copyright (C) 2013-2014 SYSTOPIA                       |
| Author: B. Endres (endres -at- systopia.de)    |
| http://www.systopia.de/                                |
+--------------------------------------------------------+
| TODO: License                                          |
+--------------------------------------------------------*/


require_once 'donrec.civix.php';
require_once 'CRM/Donrec/DataStructure.php';

/**
 * Implementation of hook_civicrm_config
 */
function donrec_civicrm_config(&$config) {
  _donrec_civix_civicrm_config($config);
}

/**
 * Implementation of hook_civicrm_xmlMenu
 *
 * @param $files array(string)
 */
function donrec_civicrm_xmlMenu(&$files) {
  _donrec_civix_civicrm_xmlMenu($files);
}

/**
 * Implementation of hook_civicrm_install
 */
function donrec_civicrm_install() {
  // create database tables
  $config = CRM_Core_Config::singleton();
  $sql = file_get_contents(dirname( __FILE__ ) .'/sql/donrec.sql', true);
  CRM_Utils_File::sourceSQLFile($config->dsn, $sql, NULL, true);

  return _donrec_civix_civicrm_install();
}

/**
 * Implementation of hook_civicrm_uninstall
 */
function donrec_civicrm_uninstall() {
  return _donrec_civix_civicrm_uninstall();
}

/**
 * Implementation of hook_civicrm_enable
 */
function donrec_civicrm_enable() {
  // create/update custom groups
  CRM_Donrec_DataStructure::update();
  // install default template
  CRM_Donrec_Logic_Template::setDefaultTemplate();

  return _donrec_civix_civicrm_enable();
}

/**
 * Implementation of hook_civicrm_disable
 */
function donrec_civicrm_disable() {
  return _donrec_civix_civicrm_disable();
}

/**
 * Implementation of hook_civicrm_upgrade
 *
 * @param $op string, the type of operation being performed; 'check' or 'enqueue'
 * @param $queue CRM_Queue_Queue, (for 'enqueue') the modifiable list of pending up upgrade tasks
 *
 * @return mixed  based on op. for 'check', returns array(boolean) (TRUE if upgrades are pending)
 *                for 'enqueue', returns void
 */
function donrec_civicrm_upgrade($op, CRM_Queue_Queue $queue = NULL) {
  return _donrec_civix_civicrm_upgrade($op, $queue);
}

/**
 * Implementation of hook_civicrm_managed
 *
 * Generate a list of entities to create/deactivate/delete when this module
 * is installed, disabled, uninstalled.
 */
function donrec_civicrm_managed(&$entities) {
  return _donrec_civix_civicrm_managed($entities);
}

/**
 * Implementation of hook_civicrm_caseTypes
 *
 * Generate a list of case-types
 *
 * Note: This hook only runs in CiviCRM 4.4+.
 */
function donrec_civicrm_caseTypes(&$caseTypes) {
  _donrec_civix_civicrm_caseTypes($caseTypes);
}

/**
* Add an action for creating donation receipts after doing a search
*
* @param string $objectType specifies the component
* @param array $tasks the list of actions
*
* @access public
*/
function donrec_civicrm_searchTasks($objectType, &$tasks) {
  if ($objectType == 'contact') {
    $tasks[] = array(
    'title' => ts('Issue donation receipt(s)'),
    'class' => 'CRM_Donrec_Form_Task_DonrecTask',
    'result' => false);
  }
}

/**
 * Set permissions for runner/engine API call
 */
function donrec_civicrm_alterAPIPermissions($entity, $action, &$params, &$permissions) {
  // TODO: adjust to correct permission
  $permissions['donation_receipt_engine']['next'] = array('access CiviCRM');
}

/**
 * Set settings
 */
function donrec_civicrm_alterSettingsFolders(&$metaDataFolders = NULL){
  static $configured = FALSE;
  if ($configured) return;
  $configured = TRUE;

  $extRoot = dirname( __FILE__ ) . DIRECTORY_SEPARATOR;
  $extDir = $extRoot . 'settings';
  if(!in_array($extDir, $metaDataFolders)){
    $metaDataFolders[] = $extDir;
  }
}

function donrec_civicrm_tabs(&$tabs, $contactID) {
    $url = CRM_Utils_System::url( 'civicrm/donrec/tab',
                                  "reset=1&snippet=1&force=1&cid=$contactID" );
    $tabs[] = array( 'id'    => 'donation_receipts',
                     'url'   => $url,
                     'title' => ts('Donation receipts'),
                     'count' => CRM_Donrec_Logic_Receipt::getReceiptCountForContact($contactID),
                     'weight' => 300);
}

/*
 * return errors if a receipted contribution is going to be changed
 */
function donrec_civicrm_validateForm( $formName, &$fields, &$files, &$form, &$errors ) {
  if ($formName == 'CRM_Contribute_Form_Contribution') {
    // do we have a contribution id?
    $id = $form->_id;
    if (empty($id)) {
      return;
    }

    // get the name of the custom-value-table for receipt-items
    $query = "
      SELECT id, table_name
      FROM civicrm_custom_group
      WHERE name = 'zwb_donation_receipt_item'";
    $result = CRM_Core_DAO::executeQuery($query);
    $result->fetch();
    $custom_group_id = $result->id;
    $table_name = $result->table_name;

    // get the column-name for the receipt-status
    $query = "
      SELECT column_name
      FROM civicrm_custom_field
      WHERE custom_group_id = $custom_group_id
      AND name = 'status'";
    $status_column_name = CRM_Core_DAO::singleValueQuery($query);

    // check if there is a original receipt for the contribution
    $query = "
      SELECT count(*)
      FROM (
        SELECT *
        FROM $table_name
        WHERE entity_id = $id
        AND $status_column_name = 'ORIGINAL'
      ) A";
    $receipt_count = (int) CRM_Core_DAO::singleValueQuery($query);

    // do nothing if no receipt with status ORIGINAL was found
    if (empty($receipt_count)) {
      return;

    // If a forbidden value was changed return an error
    } else {
      $forbidden = array(
        'financial_type_id',
        'total_amount',
        'receive_date',
        'currency',
        'contribution_status_id',
        'payment_instrument_id'
      );

      // check if forbidden columns are going to be changed
      foreach ($forbidden as $col) {
        if ($form->_values[$col] != $fields[$col]) {
          // we need a special check for dates
          if (!empty(strpos($col, 'date'))) {
            // this approach does not considers seconds!
            // (some input-formats does not allow the input of seconds at all)
            $new_date = date('d/m/Y H:i', strtotime($fields['receive_date'] . ' ' . $fields['receive_date_time']));
            $old_date = date('d/m/Y H:i', strtotime($form->_values['receive_date']));
            if ($new_date == $old_date) {
              continue;
            }
          }
          $errors[$col] = ts("This contribution was already receipted. You must not change the value for $col.");
        }
      }
    }
  }
  return;
}

/*
 * die hard if a receipted contribution is going to be changed
 */
function donrec_civicrm_pre( $op, $objectName, $id, &$params ) {
  if ($objectName == 'Contribution' && ($op == 'edit' || $op == 'delete')) {
    // get the name of the custom-value-table for receipt-items
    $query = "
      SELECT id, table_name
      FROM civicrm_custom_group
      WHERE name = 'zwb_donation_receipt_item'";
    $result = CRM_Core_DAO::executeQuery($query);
    $result->fetch();
    $custom_group_id = $result->id;
    $table_name = $result->table_name;

    // get the column-name for the receipt-status
    $query = "
      SELECT column_name
      FROM civicrm_custom_field
      WHERE custom_group_id = $custom_group_id
      AND name = 'status'";
    $status_column_name = CRM_Core_DAO::singleValueQuery($query);

    // check if there is a original receipt for the contribution
    $query = "
      SELECT count(*)
      FROM (
        SELECT *
        FROM $table_name
        WHERE entity_id = $id
        AND $status_column_name = 'ORIGINAL'
      ) A";
    $receipt_count = (int) CRM_Core_DAO::singleValueQuery($query);

    // do nothing if no receipt with status ORIGINAL was found
    if (empty($receipt_count)) {
      return;

    // Check on the values that are going to be changed.
    // Die if relevant values are going to be changed
    } elseif ($op == 'edit'){
      // columns that must not be changed
      $forbidden = array(
        'financial_type_id',
        'total_amount',
        'receive_date',
        'currency',
        'contribution_status_id',
        'payment_instrument_id'
      );

      // get the contribution
      $query = "
        SELECT *
        FROM civicrm_contribution
        WHERE id = $id";
      $result = CRM_Core_DAO::executeQuery($query);
      $result->fetch();

      // check if forbidden values are going to be changed.
      foreach ($forbidden as $col) {
        if ($result->$col != $params[$col]) {
          // we need a extra-check for dates (which are not in the same format)
          if (!empty(strpos($col, 'date')) && preg_replace('/[-: ]/', '', $result->$col) == $params[$col]) {
              continue;
          }
          error_log("The column $col of this contribution ($id) must not be changed because the contribution was already receipted!");
          die;
        }
      }

    // contribution must not be deleted! So die here!
    } else {
      error_log("This contribution ($id) must not be deleted because it was already receipted!");
      die;
    }
  }
  return;
}
