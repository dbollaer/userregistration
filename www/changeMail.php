<?php

$config = SimpleSAML_Configuration::getInstance();
$uregconf = SimpleSAML_Configuration::getConfig('module_userregistration.php');
$mailoptions = $uregconf->getArray('mail');
$formFields = $uregconf->getArray('formFields');
$store = sspmod_userregistration_Storage_UserCatalogue::instantiateStorage();
$customNavigation = $uregconf->getBoolean('custom.navigation', TRUE);
$redis_config = $uregconf->getArray('redis');

$tokenManager = new sspmod_userregistration_TokenManagement($redis_config, $mailoptions['token.lifetime']);

$systemName = array('%SNAME%' => $uregconf->getString('system.name') );

$mail_param = $store->userRegisterEmailAttr;
$uid_param = $store->userIdAttr;

/* Get a reference to our authentication source. */
$asId = $uregconf->getString('auth');
$as = new SimpleSAML_Auth_Simple($asId);

$formGen = new sspmod_userregistration_XHTML_Form($formFields, 'changeMail.php');
$formFields = $uregconf->getArray('formFields');
$showFields = sspmod_userregistration_Util::getFieldsFor('change_mail');
$formGen->fieldsToShow($showFields);

$html = new SimpleSAML_XHTML_Template(
	$config,
	'userregistration:change_mail.tpl.php',
	'userregistration:userregistration');

$html->data['customNavigation'] = $customNavigation;

if (array_key_exists('success', $_GET)) {
	$html->data['userMessage'] = 'message_chmail';

    $html->data['reLoginMessage'] = 'message_relogin_mail';

	$html->show();
	exit();	
}

$as->requireAuth();
$attributes = $as->getAttributes();

if (array_key_exists('token1', $_REQUEST) && array_key_exists('token2', $_REQUEST)){
	// Stage 3: User access page from url in e-mail
	try{
		$token1 = isset($_REQUEST['token1']) ? $_REQUEST['token1'] : null;
		$token2 = isset($_REQUEST['token2']) ? $_REQUEST['token2'] : null;

		$token1_data = $tokenManager->getDetails($token1);
		$token2_data = $tokenManager->getDetails($token2);

		if ($token1_data === false || $token2_data === false) {
			throw new sspmod_userregistration_Error_UserException('invalid_token');
		}

		$newmail = $token1_data['email'];
		$oldmail = $token2_data['email'];

        if ($attributes[$mail_param][0] != $oldmail) {
   			throw new sspmod_userregistration_Error_UserException('invalid_mail');
		}

        $user_with_mail = $store->findAndGetUser('irisMailAlternateAddress', $newmail, true);

        if (!empty($user_with_mail)) {
            if ($user_with_mail[$uid_param][0] != $attributes[$uid_param][0]) {
                throw new sspmod_userregistration_Error_UserException('mail_already_registered');
            }
        }

		$userInfo = array();
        if (isset($attributes['irisMailAlternateAddress'])) {
            $alternateAddress = $attributes['irisMailAlternateAddress'];
            if (!in_array($oldmail ,$alternateAddress)) {
                array_push($alternateAddress, $oldmail);
            }
        }
        else {
            $alternateAddress = array($oldmail);
        }

        $alternateAddress = array($oldmail);

		$userInfo['irisMailAlternateAddress'] = $alternateAddress;
        $userInfo['objectClass'] = $attributes['objectClass'];
        if (!in_array('irisPerson', $userInfo['objectClass'])) {
            array_push($userInfo['objectClass'], 'irisPerson');
        }

        if ($mail_param == $uid_param) {
            $store->renameEntry($uid_param, $attributes[$uid_param][0], $newmail);
            $store->updateUser($newmail, $userInfo);
        }
        else {
        	$userInfo[$mail_param] = $newmail;
            $store->updateUser($attributes[$uid_param][0], $userInfo);            
        }

        $as->logout(SimpleSAML_Module::getModuleURL('userregistration/changeMail.php?success'));
        exit();

	} catch (sspmod_userregistration_Error_UserException $e){
		// Some user error detected, maybe token error
		$formGen = new sspmod_userregistration_XHTML_Form($formFields, 'changeMail.php');

		$terr = new SimpleSAML_XHTML_Template(
			$config,
			'userregistration:step1_ch_email.tpl.php',
			'userregistration:userregistration');

		$error = $terr->t(
			$e->getMesgId(),
			$e->getTrVars()
		);

		$terr->data['error'] = htmlspecialchars($error);

    	if ($e->getMesgId() == 'invalid_token') {
			$terr->data['refreshtoken'] = true;
			$terr->data['newmail'] = $newmail;
		}
        else {
            $showFields = sspmod_userregistration_Util::getFieldsFor('change_mail');
		    $formGen->fieldsToShow($showFields);

            $validator = new sspmod_userregistration_Registration_Validation(
		             $formFields,
		             $showFields);

		    $values = $validator->getRawInput();

		    $formGen->setValues($values);
		    $formGen->setSubmitter('save');
    		$formHtml = $formGen->genFormHtml();
		    $terr->data['formHtml'] = $formHtml;
        }
		
		$terr->data['systemName'] = $systemName;
		$terr->data['customNavigation'] = $customNavigation;
		$terr->show();
	}
} elseif(array_key_exists('refreshtoken', $_POST)){
	// Resend token

    try {
	    $newmail = $_POST['newmail'];

        $oldmail = $attributes[$mail_param][0];

	    $newToken = $tokenManager->generate($newmail);
	    $oldToken = $tokenManager->generate($oldmail);

	    $url = SimpleSAML_Utilities::selfURL();

	    $changemailurl = SimpleSAML_Utilities::addURLparameter(
		    $url,
		    array(
			    'token1' => $newToken,
			    'token2' => $oldToken
		    )
	    );

		$mail_data = array(
			'newmail' => $newmail,
			'tokenLifetime' => $mailoptions['token.lifetime'],
			'changemailurl' => $changemailurl,
			'systemName' => $systemName,
		);

		sspmod_userregistration_Util::sendEmail(
			$newmail,
			$mailoptions['subject'],
			'userregistration:mail1_ch_token.tpl.php',
			$mail_data
		);

	    $html = new SimpleSAML_XHTML_Template(
		    $config,
		    'userregistration:step2_ch_mail_sent.tpl.php',
		    'userregistration:userregistration');
	    $html->data['newmail'] = $newmail;
	    $html->data['systemName'] = $systemName;
	    $html->data['customNavigation'] = $customNavigation;
	    $html->show();
        exit();

	} catch(sspmod_userregistration_Error_UserException $e) {
		// Some user error detected
		$formGen = new sspmod_userregistration_XHTML_Form($formFields, 'changeMail.php');

		$showFields = sspmod_userregistration_Util::getFieldsFor('change_mail');
		$formGen->fieldsToShow($showFields);

        $validator = new sspmod_userregistration_Registration_Validation(
		         $formFields,
		         $showFields);

		$values = $validator->getRawInput();

		$formGen->setValues($values);
		$formGen->setSubmitter('save');

		$formHtml = $formGen->genFormHtml();

		$terr = new SimpleSAML_XHTML_Template(
		 $config,
		 'userregistration:step1_ch_email.tpl.php',
		 'userregistration:userregistration');
		$terr->data['formHtml'] = $formHtml;

		$error = $terr->t(
			 $e->getMesgId(),
			 $e->getTrVars()
		);

		$terr->data['systemName'] = $systemName;

		$terr->data['error'] = htmlspecialchars($error);
		$terr->data['customNavigation'] = $customNavigation;
		$terr->show();
	}

} else if (array_key_exists('sender', $_REQUEST) && array_key_exists('newmail', $_REQUEST) && !empty($_REQUEST['newmail'])) {

    try {
		$showFields = sspmod_userregistration_Util::getFieldsFor('change_mail');

		$validator = new sspmod_userregistration_Registration_Validation(
		    $formFields,
		    $showFields);
		$validValues = $validator->validateInput();

		$userInfo = sspmod_userregistration_Util::processInput($validValues, $showFields, $attributes);

		$newmail = $userInfo['newmail'];

        $oldmail = $attributes[$mail_param][0];

		$newToken = $tokenManager->generate($newmail);
		$oldToken = $tokenManager->generate($oldmail);

		$url = SimpleSAML_Utilities::selfURL();

		$changemailurl = SimpleSAML_Utilities::addURLparameter(
			$url,
			array(
				'token1' => $newToken,
				'token2' => $oldToken
			)
		);

		$mail_data = array(
			'newmail' => $newmail,
			'tokenLifetime' => $mailoptions['token.lifetime'],
			'changemailurl' => $changemailurl,
			'systemName' => $systemName,
		);

		sspmod_userregistration_Util::sendEmail(
			$newmail,
			$mailoptions['subject'],
			'userregistration:mail1_ch_token.tpl.php',
			$mail_data
		);

		$html = new SimpleSAML_XHTML_Template(
			$config,
			'userregistration:step2_ch_mail_sent.tpl.php',
			'userregistration:userregistration');
		$html->data['newmail'] = $newmail;
		$html->data['systemName'] = $systemName;
		$html->data['customNavigation'] = $customNavigation;
		$html->show();
        exit();

	} catch(sspmod_userregistration_Error_UserException $e) {
		// Some user error detected
		$formGen = new sspmod_userregistration_XHTML_Form($formFields, 'changeMail.php');

		$showFields = sspmod_userregistration_Util::getFieldsFor('change_mail');
		$formGen->fieldsToShow($showFields);

        $validator = new sspmod_userregistration_Registration_Validation(
		         $formFields,
		         $showFields);

		$values = $validator->getRawInput();

		$formGen->setValues($values);
		$formGen->setSubmitter('save');

		$formHtml = $formGen->genFormHtml();

		$terr = new SimpleSAML_XHTML_Template(
		 $config,
		 'userregistration:step1_ch_email.tpl.php',
		 'userregistration:userregistration');
		$terr->data['formHtml'] = $formHtml;

		$error = $terr->t(
			 $e->getMesgId(),
			 $e->getTrVars()
		);

		$terr->data['systemName'] = $systemName;

		$terr->data['error'] = htmlspecialchars($error);
		$terr->data['customNavigation'] = $customNavigation;
		$terr->show();
	}

} elseif (array_key_exists('logout', $_GET)) {
	if ($customNavigation) {
		$as->logout($as->getLoginURL());
	}
	else {
		$as->logout(SimpleSAML_Module::getModuleURL('userregistration/index.php'));
	}
} else {
    $formGen->setSubmitter('save');
    $html->data['formHtml'] = $formGen->genFormHtml();
    $html->data['uid'] = $attributes[$store->userIdAttr][0];
    $html->show();
}
