<?php

require_once('../_include.php');

require_once((isset($SIMPLESAML_INCPREFIX)?$SIMPLESAML_INCPREFIX:'') . 'SimpleSAML/Configuration.php');
require_once((isset($SIMPLESAML_INCPREFIX)?$SIMPLESAML_INCPREFIX:'') . 'SimpleSAML/Metadata/SAMLParser.php');
require_once((isset($SIMPLESAML_INCPREFIX)?$SIMPLESAML_INCPREFIX:'') . 'SimpleSAML/XHTML/Template.php');
require_once((isset($SIMPLESAML_INCPREFIX)?$SIMPLESAML_INCPREFIX:'') . 'SimpleSAML/Utilities.php');

try {

	$config = SimpleSAML_Configuration::getInstance();

	if(array_key_exists('xmldata', $_POST)) {
		$xmldata = $_POST['xmldata'];

		$entities = SimpleSAML_Metadata_SAMLParser::parseDescriptorsString($xmldata);

		/* Get all metadata for the entities. */
		foreach($entities as &$entity) {
			$entity = array(
				'shib13-sp-remote' => $entity->getMetadata1xSP(),
				'shib13-idp-remote' => $entity->getMetadata1xIdP(),
				'saml20-sp-remote' => $entity->getMetadata20SP(),
				'saml20-idp-remote' => $entity->getMetadata20IdP(),
				);

		}

		/* Transpose from $entities[entityid][type] to $output[type][entityid]. */
		$output = SimpleSAML_Utilities::transposeArray($entities);

		/* Merge all metadata of each type to a single string which should be
		 * added to the corresponding file.
		 */
		foreach($output as $type => &$entities) {

			$text = '';

			foreach($entities as $entityId => $entityMetadata) {

				if($entityMetadata === NULL) {
					continue;
				}

				$text .= '$metadata[\'' . addslashes($entityId) . '\'] = ' .
					var_export($entityMetadata, TRUE) . ';' . "\n";
			}

			$entities = $text;
		}

	} else {
		$xmldata = '';
		$output = array();
	}


	$template = new SimpleSAML_XHTML_Template($config, 'metadata-converter.php');

	$template->data['xmldata'] = $xmldata;
	$template->data['output'] = $output;

	$template->show();

} catch(Exception $exception) {
	SimpleSAML_Utilities::fatalError('', 'METADATA_PARSER', $exception);
}

?>