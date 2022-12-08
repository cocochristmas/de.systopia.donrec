<?php
/*-------------------------------------------------------+
| SYSTOPIA Donation Receipts Extension                   |
| Copyright (C) 2013-2016 SYSTOPIA                       |
| Author: N.Bochan (bochan -at- systopia.de)             |
| http://www.systopia.de/                                |
+--------------------------------------------------------+
| License: AGPLv3, see LICENSE file                      |
+--------------------------------------------------------*/

/**
 * This is the PDF exporter base class
 */
abstract class CRM_Donrec_Exporters_EncryptedPDF extends CRM_Donrec_Exporters_BasePDF {

  // generate a random password with default length 15
  private function generate_password($length = 15) {
    $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $password = '';
    for ($i = 0; $i < $length; $i++) {
      $password .= $characters[mt_rand(0, strlen($characters) - 1)];
    }

    return $password;
  }

  // encrypt the given file if the setting in the profile says so
  protected function encrypt_file($file, $snapshot_receipt): void {

    if ($snapshot_receipt->getProfile()->getDataAttribute('enable_encryption')) {
      $this->encrypt_file($file);
      $password = $this->generate_password();
      $cmd = CRM_Donrec_Logic_Settings::get('encryption_command');
      $ouput = shell_exec(escapeshellcmd($cmd));
    }
  }
}
