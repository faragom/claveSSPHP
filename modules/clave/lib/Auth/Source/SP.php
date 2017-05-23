<?php

class sspmod_clave_Auth_Source_SP extends SimpleSAML_Auth_Source {
  
  
  private static $mandatoryConfigParams = array('providerName','entityid','QAA','certificate','privatekey',
  'idpEntityID','SingleSignOnService','certData','hostedSP');

	/**
	 * The entity ID of this SP.
	 *
	 * @var string
	 */
	private $entityId;




	/**
	 * The metadata of this SP.
	 *
	 * @var SimpleSAML_Configuration.
	 */
	private $metadata;


	/**
	 * The clave metadata of this SP.
	 *
	 * @var SimpleSAML_Configuration.
	 */
    private $claveConfig;


    
   	/**
	 * The metadata of the remote clave IdP.
	 *
	 * @var SimpleSAML_Configuration.
	 */ 
    private $idpMetadata;


    /**
	 * The IdP the user is allowed to log into (a bit reduntant variable, delete).
	 *
	 * @var string  The clave IdP entityId.
	 */
	private $idp;


    
	/**
	 * The Certificate that will be used to sign the Clave AuthnReq.
	 *
	 * @var string PEM encoded without headers.
	 */
    private $certData;


	/**
	 * The Private Key that will be used to sign the Clave AuthnReq.
	 *
	 * @var string PEM encoded without headers.
	 */
    private $keyData;


	/**
	 * Constructor for Clave-Stork-SAML SP authentication source.
	 *
	 * @param array $info  Information about this authentication source (contains AuthId, the id of this auth source).
	 * @param array $config  Configuration block of this authsource in authsources.php.
	 */
    public function __construct($info, $config) {
        assert('is_array($info)');
        assert('is_array($config)');
   
        /* Call the parent constructor first, as required by the interface. */
        parent::__construct($info, $config);
   
   
        SimpleSAML_Logger::debug('--> Calling clave auth source constructor');
        //SimpleSAML_Logger::debug('info: '.print_r($info, true));
        SimpleSAML_Logger::debug('config: '.print_r($config, true));
   
   
        $this->metadata = SimpleSAML_Configuration::loadFromArray($config, 
        'authsources['.var_export($this->authId,true).']');
        
        
        //Get hosted sp info from clave-sp-hosted metadata set
        $spConfId = $this->metadata->getString('hostedSP', NULL);
        $this->claveConfig = sspmod_clave_Tools::getMetadataSet($spConfId,"clave-sp-hosted");
        
        
        $this->entityId = $this->claveConfig->getString('entityid');

        //Get idp info from clave-idp-remote metadata set
        $idpEntityId = $this->metadata->getString('idpEntityID', NULL);
        $this->idpMetadata = sspmod_clave_Tools::getMetadataSet($idpEntityId,"clave-idp-remote");
        

        //Check if mandatory config is available   
        foreach (self::$mandatoryConfigParams as $mandParam) {
            $value = $this->metadata->getValue($mandParam, NULL);
            if ($value == NULL)
                $value = $this->claveConfig->getValue($mandParam, NULL);
            if ($value == NULL)
                $value = $this->idpMetadata->getValue($mandParam, NULL);
            if ($value == NULL)
                throw new SimpleSAML_Error_Exception("No $mandParam defined in auth source config."); 
        }
        
        $this->idp = array('endpoint' => $this->idpMetadata->getString('SingleSignOnService', NULL),
                           'cert'     => $this->idpMetadata->getString('certData', NULL));
        
        //Get SP request signing info
        $this->certData = sspmod_clave_Tools::readCertKeyFile($this->claveConfig->getString('certificate', NULL));
        $this->keyData  = sspmod_clave_Tools::readCertKeyFile($this->claveConfig->getString('privatekey', NULL)); 
   
    }
 


	/**
	 * Retrieve the entity id of this SP.
	 *
	 * @return string  The entity id of this SP.
	 */
	public function getEntityId() {

   return $this->entityId;
	}



	/**
	 * Retrieve the Clave IdP information.
	 *
	 * @return array The endpoint and certificate of the Clave IdP.
	 */
	public function getIdP() {
   
   return $this->idp;
	}



	/**
	 * Retrieve the metadata of this SP.
	 *
	 * @return SimpleSAML_Configuration  The metadata of this SP.
	 */
	public function getMetadata() {
   
   return $this->metadata;
	}



	/**
	 * Start login.
	 *
	 * This function saves the information about the login, and redirects to the IdP.
	 *
	 * @param array &$state  Information about the current authentication.
	 */
	public function authenticate(&$state) {
   assert('is_array($state)');
   
   SimpleSAML_Logger::info("clave auth filter authenticate!!");
   
   
   // We are going to need the authId in order to retrieve this authentication source later.
   $state['clave:sp:AuthId'] = $this->authId;
   
   SimpleSAML_Logger::info("state: ".print_r($state,true));
   SimpleSAML_Logger::info("metadata: ".print_r($this->metadata,true));
   
   $this->startSSO( $this->idp, $state);
   assert('FALSE');   
	}

 

 
	/**
	 * Send a SSO request to Clave IdP.
	 *
	 * @param array $idp  The endpoint and certificate of the IdP.
	 * @param array $state  The state array for the current authentication.
	 */
	public function startSSO(array $idp, array $state) {
   
   SimpleSAML_Logger::info("clave auth filter startSSO");
      
   SimpleSAML_Logger::info("SP sign cert: ".$this->certData);

   
   $spConf  = SimpleSAML_Configuration::loadFromArray($state['SPMetadata']);

   //These params are read from the SP remote config and if not set, from the IdP hosted config.
   $SPCountry = $spConf->getString('spCountry',$this->claveConfig->getString('spCountry', 'NOTSET'));
   $SPsector  = $spConf->getString('spSector',$this->claveConfig->getString('spSector', 'ANY'));
   $SPinstitution = $spConf->getString('spInstitution',$this->claveConfig->getString('spInstitution', 'ANY'));
   $SPapp = $spConf->getString('spApplication',$this->claveConfig->getString('spApplication', 'ANY'));
//   $SpId="$SPCountry-$SPsector-$SPinstitution-$SPapp";
   $SpId = $spConf->getString('spID',$this->claveConfig->getString('spID', 'NOTSET'));
   
   $CitizenCountry = $spConf->getString('citizenCountryCode',$this->claveConfig->getString('citizenCountryCode', 'NOTSET'));
   
   $QAA =  $spConf->getInteger('QAA', $this->metadata->getInteger('QAA', 1));
   
   $sectorShare      = $spConf->getBoolean('eIDSectorShare', $this->claveConfig->getBoolean('eIDSectorShare', true));
   $crossSectorShare = $spConf->getBoolean('eIDCrossSectorShare', $this->claveConfig->getBoolean('eIDCrossSectorShare', true));
   $crossBorderShare = $spConf->getBoolean('eIDCrossBorderShare', $this->claveConfig->getBoolean('eIDCrossBorderShare', true));
   
   $reqIssuer = $spConf->getString('issuer', $this->claveConfig->getString('issuer', $this->entityId));
   
   
   //Get address of assertion consumer service for this module (it
   //ends with the id of the authsource, so we can retrieve the
   //correct authsource config on the acs)
   $returnPage = SimpleSAML_Module::getModuleURL('clave/sp/clave-acs.php/'.$this->authId);


   $clave = new sspmod_clave_SPlib();

   //We get the forceAuthn of the AuthnReq and reproduce it, default is false
   //if($this->metadata->getInteger('ForceAuthn', 0) == 1)

   //if (isset($state['ForceAuthn']) && $state['ForceAuthn'] === 1)
   SimpleSAML_Logger::debug("************************FA:".$state['ForceAuthn']);

   if( ((bool)$state['ForceAuthn']) === true)
       $clave->forceAuthn();
   
   $clave->setSignatureKeyParams($this->certData, $this->keyData, sspmod_clave_SPlib::RSA_SHA256);
   $clave->setSignatureParams(sspmod_clave_SPlib::SHA256,sspmod_clave_SPlib::EXC_C14N);
   
   $clave->setServiceProviderParams($this->claveConfig->getString('providerName', NULL), 
                                    $reqIssuer,
                                    $returnPage);
   
   $clave->setSPLocationParams($SPCountry,$SPsector,$SPinstitution,$SPapp);  
   $clave->setSPVidpParams($SpId,$CitizenCountry);
   $clave->setSTORKParams ($idp['endpoint'], $QAA, $sectorShare, $crossSectorShare, $crossBorderShare);
   
   
   //Get remote sp metadata and get attributes to request 
   $attrsToRequest = $state['SPMetadata']['attributes'];
   if($attrsToRequest == NULL || count($attrsToRequest)<=0)
     $attrsToRequest = array("eIdentifier","givenName","surname");
   foreach($attrsToRequest as $attr)
     $clave->addRequestAttribute ($attr, false);
   
   
   //Save information needed for the comeback
   //   $state['clave:sp:reqTime']        = $clave->getRequestTimestamp();
   $state['clave:sp:returnPage']     = $returnPage;
   $state['clave:sp:mandatoryAttrs'] = array();
   $id = SimpleSAML_Auth_State::saveState($state, 'clave:sp:req', true);
   SimpleSAML_Logger::debug("Generated Req ID: ".$id);
   
   
   //Set the id of the request, it must be the id of the saved state.
   $clave->setRequestId($id);
   
   
   //Build authn request
   $req = base64_encode($clave->generateStorkAuthRequest());
   SimpleSAML_Logger::debug("Generated AuthnReq: ".$req);
   
   //Perform redirection
   $this->redirect($idp['endpoint'],$req, $state);
   
   assert('FALSE');   
 }
    
 
    
  /**
   * Handle a response from a Clave request.
   *
   * @param array $state  The authentication state.
   * @param array $attributes  The attributes.
   */
  public function handleResponse(array $state, array $attributes) {
      
      $spMetadataArray = $this->metadata->toArray();
      
      //Add received attributes to the state that will be returned to the IdP
      $state['Attributes'] = $attributes;

      //Return control to the ssp IdP part
      SimpleSAML_Auth_Source::completeAuth($state);
  }
  
  
  
 private function redirect($destination, $req, $state){
   
   // If set per SP, this value is prioritised.
   $spConf  = SimpleSAML_Configuration::loadFromArray($state['SPMetadata']);
   $idpConf = $this->metadata;


   $post = array('SAMLRequest'  => $req);

   //IdP config values are the default if sp values not found, else all is default
   $idpList = $spConf->getArray('idpList', $idpConf->getArray('idpList', array()));
   if(count($idpList)>0)
       $post['idpList '] = sspmod_clave_Tools::serializeIdpList($idpList);


   $idpExcludedList = $spConf->getArray('idpExcludedList', $idpConf->getArray('idpExcludedList', array()));
   if(count($idpExcludedList)>0)
       $post['excludedIdPList '] = sspmod_clave_Tools::serializeIdpList($idpExcludedList);    
   
   //Force a certain auth source
   $force = $spConf->getString('force', $idpConf->getString('force', NULL));
   if ($force != NULL)
     $post['forcedIdP'] = $force;


   //Allow legal person certificates to be used
   $legal = $spConf->getBoolean('allowLegalPerson', false);
   if ($legal === true)
       $post['allowLegalPerson'] = 'true';
   
   //Redirecting to Clave IdP (Only HTTP-POST binding supported)
   SimpleSAML_Utilities::postRedirect($destination, $post);
   
 }







	/**
	 * Start logout operation.
	 *
	 * @param array $state  The logout state.
	 */
	public function logout(&$state) {
		assert('is_array($state)');
        
        $this->startSLO2($state);
        return;
	}


	/**
	 * Start a SAML 2 logout operation.
	 *
	 * @param array $state  The logout state.
	 */
	public function startSLO2(&$state) {
		assert('is_array($state)');
        
        $providerName = $this->claveConfig->getString('providerName', NULL);
        
		$endpoint = $this->idpMetadata->getString('SingleLogoutService', NULL);
        if ($endpoint === NULL) {
			SimpleSAML_Logger::info('No logout endpoint for clave remote IdP.');
			return;
		}
        
        //Get address of logout consumer service for this module (it
        //ends with the id of the authsource, so we can retrieve the
        //correct authsource config on the acs)
        $returnPage = SimpleSAML_Module::getModuleURL('clave/sp/logout-return.php/'.$this->authId);


        $clave = new sspmod_clave_SPlib();
   
        $clave->setSignatureKeyParams($this->certData, $this->keyData,
                                      sspmod_clave_SPlib::RSA_SHA256);
        $clave->setSignatureParams(sspmod_clave_SPlib::SHA256,sspmod_clave_SPlib::EXC_C14N);
        
        
        //Save information needed for the comeback
        $state['clave:sp:slo:returnPage'] = $returnPage;
        $id = SimpleSAML_Auth_State::saveState($state, 'clave:sp:slo:req', true);
        SimpleSAML_Logger::debug("Generated Req ID: ".$id);
        
        
        //Generate the logout requestx
        $req = base64_encode($clave->generateSLORequest($providerName,
                                                        $endpoint,
                                                        $returnPage,
                                                        $id));
        SimpleSAML_Logger::debug("Generated LogoutRequest: ".$req);
        
        //Perform redirection
        $post = array('samlRequestLogout'  => $req);
     
        //Redirecting to Clave IdP (Only HTTP-POST binding supported)
        SimpleSAML_Utilities::postRedirect($endpoint, $post);
        
        
        /*
        //Stork SLO doesn't use standard SAML2, so we must reimplement it
        
		$lr = sspmod_saml_Message::buildLogoutRequest($this->claveConfig, $this->idpMetadata);
        //Stork does not use nameID
		$lr->setNameId(array(
            'Value' => $this->claveConfig->getString('providerName', NULL),
            //'NameQualifier' => '',
            //'SPNameQualifier' => '',
            'Format' => 'urn:oasis:names:tc:SAML:1.0:nameid-format:unspecified',
        ));
		//$lr->setSessionIndex($sessionIndex);
		$lr->setRelayState($id);
		$lr->setDestination($endpoint);
        $lr->setId($id);
        
        //Always POST binding in Clave
		$b = SAML2_Binding::getBinding('urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST');
		$b->send($lr);
        */
        
		assert('FALSE');
	}



 
 
}
