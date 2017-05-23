<?php

/**
 * Assertion consumer service handler for clave authentication source SP
 *
 */

//Some Clave specific stuff
$expectedAdditionalPostParams = array('isLegalPerson', 'oid');


$returnedAttributes = array();


SimpleSAML_Logger::info('Call to Clave auth source acs');


// Get the Id of the authsource
$sourceId = substr($_SERVER['PATH_INFO'], 1);
$source = SimpleSAML_Auth_Source::getById($sourceId, 'sspmod_clave_Auth_Source_SP');

//Get the metadata for the soliciting SP
$spMetadata = $source->getMetadata();
SimpleSAML_Logger::debug('Metadata on acs:'.print_r($spMetadata,true));


if(!isset($_REQUEST['SAMLResponse']))
   	throw new SimpleSAML_Error_BadRequest('No SAMLResponse POST param received.');

$resp = base64_decode($_REQUEST['SAMLResponse']);
SimpleSAML_Logger::debug("Received response: ".$resp);


//Add additional post params as attributes on the response (it is
//expected that these params will be promoted to attrs in the future)
foreach ($_POST as $name => $value){
    if(in_array($name,$expectedAdditionalPostParams))
        $returnedAttributes[$name] = $value;
}


$clave = new sspmod_clave_SPlib();


$id = $clave->getInResponseToFromReq($resp);


$state = SimpleSAML_Auth_State::loadState($id, 'clave:sp:req');
SimpleSAML_Logger::debug('State on acs:'.print_r($state,true));



$idpData = $source->getIdP();


// Warning! issuer is variale when authenticating on stork (they put
// the country code of origin of the citizen in there).
$expectedIssuers = NULL;


SimpleSAML_Logger::debug("Certificate in source: ".$idpData['cert']);
$clave->addTrustedCert($idpData['cert']);

$clave->setValidationContext($id,
                             $state['clave:sp:returnPage'],
                             $expectedIssuers,
                             $state['clave:sp:mandatoryAttrs']);

$clave->validateStorkResponse($resp);


//If later these attributes are passed from the POST to the SAML
//token, the values coming on the token will prevail
$returnedAttributes = array_merge($returnedAttributes, $clave->getAttributes());





//Authentication was successful
$errInfo = "";
if($clave->isSuccess($errInfo))  
  $source->handleResponse($state, $returnedAttributes);



//Handle auth error:

//For some reason, Clave may not return a main status code. In that case, we set responder error
if($errInfo['MainStatusCode'] == sspmod_clave_SPlib::ATST_NOTAVAIL){
  $errInfo['MainStatusCode'] = sspmod_clave_SPlib::ST_RESPONDER;
}
//Forward the Clave IdP error to our remote SP.
SimpleSAML_Auth_State::throwException($state,
                                      new sspmod_saml_Error($errInfo['MainStatusCode'],
                                                            $errInfo['SecondaryStatusCode'],
                                                            $errInfo['StatusMessage']));


assert('FALSE');
