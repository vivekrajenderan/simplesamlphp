<?php

/**
 * This SAML 2.0 endpoint can receive incomming LogoutRequests. It will also send LogoutResponses, 
 * and LogoutRequests and also receive LogoutResponses. It is implemeting SLO at the SAML 2.0 IdP.
 *
 * @author Andreas �kre Solberg, UNINETT AS. <andreas.solberg@uninett.no>
 * @package simpleSAMLphp
 * @version $Id$
 */

// TODO: Show error message, when shibboleth sp is logged in.
// TODO: Propagate HTTP-REDIRECT SLO on SAML 2.0 bridge.

require_once('../../../www/_include.php');

require_once((isset($SIMPLESAML_INCPREFIX)?$SIMPLESAML_INCPREFIX:'') . 'SimpleSAML/Utilities.php');
require_once((isset($SIMPLESAML_INCPREFIX)?$SIMPLESAML_INCPREFIX:'') . 'SimpleSAML/Session.php');
require_once((isset($SIMPLESAML_INCPREFIX)?$SIMPLESAML_INCPREFIX:'') . 'SimpleSAML/Logger.php');
require_once((isset($SIMPLESAML_INCPREFIX)?$SIMPLESAML_INCPREFIX:'') . 'SimpleSAML/Metadata/MetaDataStorageHandler.php');
require_once((isset($SIMPLESAML_INCPREFIX)?$SIMPLESAML_INCPREFIX:'') . 'SimpleSAML/XML/SAML20/LogoutRequest.php');
require_once((isset($SIMPLESAML_INCPREFIX)?$SIMPLESAML_INCPREFIX:'') . 'SimpleSAML/XML/SAML20/LogoutResponse.php');
require_once((isset($SIMPLESAML_INCPREFIX)?$SIMPLESAML_INCPREFIX:'') . 'SimpleSAML/Bindings/SAML20/HTTPRedirect.php');
require_once((isset($SIMPLESAML_INCPREFIX)?$SIMPLESAML_INCPREFIX:'') . 'SimpleSAML/XHTML/Template.php');


$config = SimpleSAML_Configuration::getInstance();
$metadata = SimpleSAML_Metadata_MetaDataStorageHandler::getMetadataHandler();
$session = SimpleSAML_Session::getInstance();

SimpleSAML_Logger::info('SAML2.0 - IdP.SingleLogoutService: Accessing SAML 2.0 IdP endpoint SingleLogoutService');

if (!$config->getValue('enable.saml20-idp', false))
	SimpleSAML_Utilities::fatalError(isset($session) ? $session->getTrackID() : null, 'NOACCESS');

try {
	$idpentityid = $metadata->getMetaDataCurrentEntityID('saml20-idp-hosted');
} catch (Exception $exception) {
	SimpleSAML_Utilities::fatalError($session->getTrackID(), 'METADATA', $exception);
}

SimpleSAML_Logger::debug('SAML2.0 - IdP.SingleLogoutService: Got IdP entity id: ' . $idpentityid);

/**
 * If we get an incomming LogoutRequest then we initiate the logout process.
 * in this case an SAML 2.0 SP is sending an request, which also is referred to as
 * SP initiated Single Logout.
 *
 */
if (isset($_GET['SAMLRequest'])) {

	SimpleSAML_Logger::debug('SAML2.0 - IdP.SingleLogoutService: Got SAML reuqest');

	$binding = new SimpleSAML_Bindings_SAML20_HTTPRedirect($config, $metadata);

	try {
		$logoutrequest = $binding->decodeLogoutRequest($_GET);

		if ($binding->validateQuery($logoutrequest->getIssuer(),'IdP')) {
			SimpleSAML_Logger::info('SAML2.0 - IdP.SingleLogoutService: Valid signature found for '.$logoutrequest->getRequestID());
		}

	} catch(Exception $exception) {
	
		SimpleSAML_Utilities::fatalError($session->getTrackID(), 'LOGOUTREQUEST', $exception);
		
	}
	
	// Extract some parameters from the logout request
	#$requestid = $logoutrequest->getRequestID();
	$requester = $logoutrequest->getIssuer();
	#$relayState = $logoutrequest->getRelayState();

	//$responder = $config->getValue('saml2-hosted-sp');
	$responder = $metadata->getMetaDataCurrentEntityID('saml20-idp-hosted');
	
	
	SimpleSAML_Logger::info('SAML2.0 - IdP.SingleLogoutService: got Logoutrequest from ' . $logoutrequest->getIssuer());
	SimpleSAML_Logger::stats('saml20-idp-SLO spinit ' . $requester . ' ' . $responder);
	
	/* Check if we have a valid session. */
	if($session === NULL) {
	
		/* Invalid session. To prevent the user from being unable to
		 * log out from the service provider, we should just return a
		 * LogoutResponse pretending that the logout was successful to
		 * the SP that sent the LogoutRequest.
		 */

		SimpleSAML_Logger::info('SAML2.0 - IdP.SingleLogoutService: Did not find a session here, but we are returning a LogoutResponse anyway.');

		$spentityid = $logoutrequest->getIssuer();

		/* Generate the response. */
		$response = new SimpleSAML_XML_SAML20_LogoutResponse($config, $metadata);
		$responseText = $response->generate($idpentityid, $spentityid, $logoutrequest->getRequestID(), 'IdP');

		/* Retrieve the relay state from the request. */
		$relayState = $logoutrequest->getRelayState();

		/* Send the response using the HTTP-Redirect binding. */
		$binding = new SimpleSAML_Bindings_SAML20_HTTPRedirect($config,
		$metadata);
		$binding->sendMessage($responseText, $idpentityid, $spentityid, $relayState,
			'SingleLogoutService', 'SAMLResponse', 'IdP');
		exit;
	}


	$session->setAuthenticated(false, $session->getAuthority() );


	/*
	 * Create an assoc array of the request to store in the session cache.
	 */
	$requestcache = array(
		'Issuer'    	=> $logoutrequest->getIssuer(),
		'RequestID'		=> $logoutrequest->getRequestID()
	);
	if ($relaystate = $logoutrequest->getRelayState() )
		$requestcache['RelayState'] = $relaystate;
		
	$session->setLogoutRequest($requestcache);
	SimpleSAML_Logger::debug('SAML2.0 - IDP.SingleLogoutService: Setting cached request with issuer ' . $logoutrequest->getIssuer());
	
	$session->set_sp_logout_completed($logoutrequest->getIssuer() );
	

	/*
	 * We receive a Logout Response to a Logout Request that we have issued earlier.
	 */
} elseif (isset($_GET['SAMLResponse'])) {

	SimpleSAML_Logger::debug('SAML2.0 - IdP.SingleLogoutService: Got SAML response');

	$binding = new SimpleSAML_Bindings_SAML20_HTTPRedirect($config, $metadata);

	try {
		$loginresponse = $binding->decodeLogoutResponse($_GET);
		
		SimpleSAML_Logger::debug('SAML2.0 - IdP.SingleLogoutService: SAML response parsed. Issuer is: ' . $loginresponse->getIssuer());

		if ($binding->validateQuery($loginresponse->getIssuer(),'IdP','SAMLResponse')) {
			SimpleSAML_Logger::info('SAML2.0 - IDP.SingleLogoutService: Valid signature found');
		}


	} catch(Exception $exception) {

		SimpleSAML_Utilities::fatalError($session->getTrackID(), 'LOGOUTRESPONSE', $exception);

	}



	$session->set_sp_logout_completed($loginresponse->getIssuer());

	SimpleSAML_Logger::info('SAML2.0 - IDP.SingleLogoutService: got LogoutResponse from ' . $loginresponse->getIssuer());
} else {
	
	SimpleSAML_Logger::debug('SAML2.0 - IdP.SingleLogoutService: No request or response...');
	/**
	 * This error message was removed 2008-02-27, because it interrupts with bridged SLO.
	 *
	 * SimpleSAML_Utilities::fatalError($session->getTrackID(), 'SLOSERVICEPARAMS');
	 */
}

$lookformore = true;
$spentityid = null;
do {
	/* Dump the current sessions (for debugging). */
	$session->dump_sp_sessions();

	/*
	 * We proceed to send logout requests to all remaining SPs.
	 */
	$spentityid = $session->get_next_sp_logout();
	
	
	// If there are no more SPs left, then we will not look for more SPs.
	if (empty($spentityid)) $lookformore = false;
	
	try {
		$spmetadata = $metadata->getMetadata($spentityid, 'saml20-sp-remote');
	} catch (Exception $e) {
		continue;
	}
	
	// If the SP we found have an SingleLogout endpoint then we will use it, and
	// hence we do not need to look for more yet.
	if (array_key_exists('SingleLogoutService', $spmetadata) && 
		!empty($spmetadata['SingleLogoutService']) ) $lookformore = false;
		
	if ($lookformore)
		SimpleSAML_Logger::info('SAML2.0 - IDP.SingleLogoutService: Will not logout from ' . $spentityid . ' looking for more SPs');

} while ($lookformore);


if ($spentityid) {

	SimpleSAML_Logger::info('SAML2.0 - IDP.SingleLogoutService: Logout next SP ' . $spentityid);

	try {
		$lr = new SimpleSAML_XML_SAML20_LogoutRequest($config, $metadata);

		// ($issuer, $receiver, $nameid, $nameidformat, $sessionindex, $mode) {
		$req = $lr->generate($idpentityid, $spentityid, $session->getNameID(), $session->getSessionIndex(), 'IdP');

		$httpredirect = new SimpleSAML_Bindings_SAML20_HTTPRedirect($config, $metadata);

		$relayState = SimpleSAML_Utilities::selfURL();
		if (isset($_GET['RelayState'])) {
			$relayState = $_GET['RelayState'];
		}

		//$request, $remoteentityid, $relayState = null, $endpoint = 'SingleLogoutService', $direction = 'SAMLRequest', $mode = 'SP'
		$httpredirect->sendMessage($req, $idpentityid, $spentityid, $relayState, 'SingleLogoutService', 'SAMLRequest', 'IdP');

		exit();

	} catch(Exception $exception) {

		SimpleSAML_Utilities::fatalError($session->getTrackID(), 'GENERATELOGOUTREQUEST', $exception);

	}


}

if ($config->getValue('debug', false))
	SimpleSAML_Logger::info('SAML2.0 - IdP.SingleLogoutService: LogoutService: All SPs done ');



/**
 * If there exists a local valid session with the SAML 2.0 module as an authority, 
 * initiate SAML 2.0 SP Single LogOut, with the RelayState equal this URL.
 */
if ($session->getAuthority() == 'saml2') {
	SimpleSAML_Utilities::redirect('/' . $config->getBaseURL() . 'saml2/sp/initSLO.php',
		array('RelayState' => SimpleSAML_Utilities::selfURLNoQuery())
	);
}

if ($session->getAuthority() == 'shib13') {
	/**
	 * TODO: Show warning to inform the user that he is logged on through an Shibboleth 1.3 IdP that
	 * do not support logout.
	 */
}




/*
 * Logout procedure is done and we send a Logout Response back to the SP
 */

try {

	$requestcache = $session->getLogoutRequest();
	if (!$requestcache) {
		throw new Exception('Could not get reference to the logout request.');
	}
	
	SimpleSAML_Logger::debug('SAML2.0 - IdP.SingleLogoutService: Found request cache with these keys: ' . join(',', array_keys($requestcache)));
	
	/**
	 * Clean up session object to save storage.
	 */
	if ($config->getValue('debug', false)) 
		SimpleSAML_Logger::info('SAML2.0 - IdP.SingleLogoutService: Session Size before cleaning: ' . $session->getSize());
		
	$session->clean();
	
	if ($config->getValue('debug', false)) 
		SimpleSAML_Logger::info('SAML2.0 - IdP.SingleLogoutService: Session Size after cleaning: ' . $session->getSize());
	
	
	/*
	 * Check if the Single Logout procedure is initated by an SP (alternatively IdP initiated SLO
	 */
	if (array_key_exists('Issuer', $requestcache)) {
		
		/**
		 * Create a Logot Response.
		 */
		$rg = new SimpleSAML_XML_SAML20_LogoutResponse($config, $metadata);
	
		// generate($issuer, $receiver, $inresponseto, $mode )
		$logoutResponseXML = $rg->generate($idpentityid, $requestcache['Issuer'], $requestcache['RequestID'], 'IdP');
	
		// Create a HTTP-REDIRECT Binding.
		$httpredirect = new SimpleSAML_Bindings_SAML20_HTTPRedirect($config, $metadata);
	
		// Find the relaystate if cached.
		$relayState = isset($requestcache['RelayState']) ? $requestcache['RelayState'] : null;
	
		// Parameters: $request, $remoteentityid, $relayState = null, $endpoint = 'SingleLogoutService', $direction = 'SAMLRequest', $mode = 'SP'
		$httpredirect->sendMessage($logoutResponseXML, $idpentityid, $requestcache['Issuer'], $relayState, 'SingleLogoutService', 'SAMLResponse', 'IdP');
		exit;
		
	} elseif (array_key_exists('RelayState', $requestcache)) {

		SimpleSAML_Utilities::redirect($requestcache['RelayState']);
		exit;
		
	} else {
	
		echo 'You are logged out'; exit;
	
	}

} catch(Exception $exception) {
	
	SimpleSAML_Utilities::fatalError($session->getTrackID(), 'GENERATELOGOUTRESPONSE', $exception);
	
}

