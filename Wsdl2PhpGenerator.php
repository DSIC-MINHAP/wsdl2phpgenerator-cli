<?php

include_once('Wsdl2PhpConfig.php');
include_once('Wsdl2PhpException.php');
include_once('Wsdl2PhpValidator.php');

// Php code classes
include_once('phpSource/PhpFile.php');
include_once('phpSource/PhpVariable.php');
include_once('phpSource/PhpDocComment.php');
include_once('phpSource/PhpDocElementFactory.php');

// TODO: This is just a basic working version, alot of things need to be fixed and perfected

/**
 * Class that contains functionality for generating classes from a wsdl file
 *
 * @package Wsdl2PhpGenerator
 * @author Fredrik Wallgren <fredrik@wallgren.me>
 * @license http://www.opensource.org/licenses/mit-license.php MIT License
 */
class Wsdl2PhpGenerator
{
  /**
   * A SoapClient for loading the WSDL
   * @var SoapClient
   * @access private
   */
  private $client = null;

  /**
   * DOM document used to load and parse the wsdl
   * @var DOMDocument
   * @access private
   */
  private $dom = null;

  /**
   * A phpSource code representation of the client
   *
   * @var PhpClass The service class
   */
  private $service;

  /**
   * An array of class objects that represents the complexTypes in the service
   *
   * @var array Array of PhpClass objects
   */
  private $types;

  /**
   * The validator to use
   *
   * @var Wsdl2PhpValidator
   * @access private
   */
  private $validator;

  /**
   * This is the object that holds the current config
   *
   * @var Wsdl2PhpConfig
   * @access private
   */
  private $config;

  /**
   * Construct the generator
   */
  public function __construct()
  {
    $this->validator = new Wsdl2PhpValidator();
    $this->service = null;
    $this->types = array();
  }

  /**
   * Generates php source code from a wsdl file
   *
   * @see Wsdl2PhpConfig
   * @param Wsdl2PhpConfig $config The config to use for generation
   * @access public
   */
  public function generate(Wsdl2PhpConfig $config)
  {
    $this->config = $config;

    $this->load();

    $this->savePhp();
  }

  /**
   * Load the wsdl file into php
   */
  private function load()
  {
    $wsdl = $this->config->getInputFile();

    try
    {
      $this->client = new SoapClient($wsdl);
    }
    catch(SoapFault $e)
    {
      throw new Exception('Error connectiong to to the wsdl. Error: '.$e->getMessage());
    }

    $this->dom = DOMDocument::load($wsdl);

    $this->loadTypes();
    $this->loadService();
  }

  /**
   * Loads the service class
   *
   * @access private
   */
  private function loadService()
  {
    $serviceName = $this->dom->getElementsByTagNameNS('*', 'service')->item(0)->getAttribute('name');
    $serviceName = $this->validator->validateClass($serviceName);
    $this->service = new PhpClass($serviceName, $this->config->getClassExists(), 'SoapClient');

    $comment = new PhpDocComment();
    $comment->addParam(PhpDocElementFactory::getParam('string', 'wsdl', 'The wsdl file to use'));
    $comment->addParam(PhpDocElementFactory::getParam('array', 'config', 'A array of config values'));
    $comment->setAccess(PhpDocElementFactory::getPublicAccess());

    $source = '  foreach(self::$classmap as $key => $value)
  {
    if(!isset($options[\'classmap\'][$key]))
    {
      $options[\'classmap\'][$key] = $value;
    }
  }
  '.$this->generateServiceOptions($this->config).'
  parent::__construct($wsdl, $options);'.PHP_EOL;

    $function = new PhpFunction('public', '__construct', '$wsdl = \''.$this->config->getInputFile().'\', $options = array()', $source, $comment);

    $this->service->addFunction($function);

    $name = 'classmap';
    $comment = new PhpDocComment();
    $comment->setAccess(PhpDocElementFactory::getPrivateAccess());
    $comment->setVar(PhpDocElementFactory::getVar('array', $name, 'The defined classes'));

    $init = 'array('.PHP_EOL;
    foreach ($this->types as $type)
    {
      $init .= "  '".$type->getIdentifier()."' => '".$type->getIdentifier()."',".PHP_EOL;
    }
    $init = substr($init, 0, strrpos($init, ','));
    $init .= ')';
    $var = new PhpVariable('private static', $name, $init, $comment);
    $this->service->addVariable($var);

    // get operations
    $operations = $this->client->__getFunctions();
    foreach($operations as $operation)
    {
      $matches = array();
      if(preg_match('/^(\w[\w\d_]*) (\w[\w\d_]*)\(([\w\$\d,_ ]*)\)$/', $operation, $matches))
      {
        $returns = $matches[1];
        $call = $matches[2];
        $params = $matches[3];
      }
      else if(preg_match('/^(list\([\w\$\d,_ ]*\)) (\w[\w\d_]*)\(([\w\$\d,_ ]*)\)$/', $operation, $matches))
      {
        $returns = $matches[1];
        $call = $matches[2];
        $params = $matches[3];
      }
      else
      {
        // invalid function call
        throw new Wsdl2PhpException('Invalid function call: '.$function);
      }

      $name = $this->validator->validateNamingConvention($call);

      $comment = new PhpDocComment();
      $comment->setAccess(PhpDocElementFactory::getPublicAccess());

      $source = '  return $this->__soapCall(\''.$name.'\', array(';
      foreach (explode(', ', $params) as $param)
      {
        $val = explode(' ', $param);
        if (count($val) == 1)
        {
          $source .= $val[0].', ';
        }
        else
        {
          $source .= $val[1].', ';
        }
        if (strlen(@$val[1]) > 0)
        {
          $comment->addParam(PhpDocElementFactory::getParam($val[0], $val[1], ''));
        }
      }
      // Remove last comma
      $source = substr($source, 0, -2);
      $source .= '));'.PHP_EOL;

      $function = new PhpFunction('public', $name, $params, $source, $comment);

      if ($this->service->functionExists($function->getIdentifier()) == false)
      {
        $this->service->addFunction($function);
      }
    }
  }

  /**
   *
   * @param Wsdl2PhpConfig $config The config containing the values to use
   *
   * @return string Returns the string for the options array
   */
  private function generateServiceOptions(Wsdl2PhpConfig $config)
  {
    $ret = '';

    if (count($config->getOptionFeatures()) > 0)
    {
      $i = 0;
      $ret .= "
  if (isset(\$options['features']) == false)
  {
    \$options['features'] = ";
      foreach ($config->getOptionFeatures() as $option)
      {
        if ($i++ > 0)
        {
          $ret .= ' | ';
        }

        $ret .= $option;
      }

      $ret .= ";
  }".PHP_EOL;
    }

    if (strlen($config->getWsdlCache()) > 0)
    {
      $ret .= "
  if (isset(\$options['wsdl_cache']) == false)
  {
    \$options['wsdl_cache'] = ".$config->getWsdlCache();
      $ret .= ";
  }".PHP_EOL;
    }

    if (strlen($this->config->getCompression()) > 0)
    {
      $ret .= "
  if (isset(\$options['compression']) == false)
  {
    \$options['compression'] = ".$config->getCompression();
       $ret .= ";
  }".PHP_EOL;
    }

    return $ret;
  }

  /**
   * Loads all type classes
   *
   * @access private
   */
  private function loadTypes()
  {
    $types = $this->client->__getTypes();

    foreach($types as $type)
    {
      $parts = explode("\n", $type);
      $className = explode(" ", $parts[0]);
      $className = $className[1];

      if( substr($className, -2, 2) == '[]' || substr($className, 0, 7) == 'ArrayOf')
      {
        // skip arrays
        continue;
      }

      $members = array();
      for($i = 1; $i < count($parts) - 1; $i++)
      {
        $parts[$i] = trim($parts[$i]);
        list($type, $member) = explode(" ", substr($parts[$i], 0, strlen($parts[$i])-1) );

        if(strpos($member, ':'))
        {
          $arr = explode(':', $member);
          $member = $arr[1];
        }

        $add = true;
        foreach($members as $mem)
        {
          if($mem['member'] == $member)
          {
            $add = false;
          }
        }

        if($add)
        {
          $members[] = array('member' => $member, 'type' => $type);
        }
      }

      // gather enumeration values
      $values = array();
      if(count($members) == 0)
      {
        $theNode = null;

        $typesNode  = $this->dom->getElementsByTagName('types')->item(0);
        $schemaList = $typesNode->getElementsByTagName('schema');

        for ($i = 0; $i < $schemaList->length; $i++)
        {
          $children = $schemaList->item($i)->childNodes;
          for ($j = 0; $j < $children->length; $j++)
          {
            $node = $children->item($j);
            if ($node instanceof DOMElement && $node->hasAttributes() && $node->attributes->getNamedItem('name')->nodeValue == $className)
            {
              $theNode = $node;
            }
          }
        }

        if($theNode)
        {
          $valueList = $theNode->getElementsByTagName('enumeration');
          if($valueList->length > 0)
          {
            for($i = 0; $i < $valueList->length; $i++)
            {
              $values[] = $valueList->item($i)->attributes->getNamedItem('value')->nodeValue;
            }
          }
        }
      }

      $class = new PhpClass($className, $this->config->getClassExists());
      foreach ($members as $varArr)
      {
        $type = $this->validator->validateType($varArr['type']);
        $name = $this->validator->validateNamingConvention($varArr['member']);
        $comment = new PhpDocComment();
        $comment->setVar(PhpDocElementFactory::getVar($type, $name, ''));
        $comment->setAccess(PhpDocElementFactory::getPublicAccess());
        $var = new PhpVariable('public', $name, '', $comment);
        $class->addVariable($var);
      }

      $this->types[] = $class;
    }
  }

  /**
   * Save all the loaded classes to the configured output dir
   *
   * @throws Wsdl2PhpException If no service is loaded
   * @throws Wsdl2PhpException If the output dir does not exist and can't be created
   *
   * @access private
   */
  private function savePhp()
  {
    $outputDirectory = $this->config->getOutputDir();

    if ($this->service === null)
    {
      throw new Wsdl2PhpException('No service loaded');
    }

    $useNamespace = (strlen($this->config->getNamespaceName()) > 0);

    //Try to create output dir if non existing
    if (is_dir($outputDirectory) == false && is_file($outputDirectory) == false)
    {
      if(mkdir($outputDirectory, 0777, true) == false)
      {
        throw new Wsdl2PhpException('Could not create output directory and it does not exist!');
      }
    }

    if ($this->config->getOneFile())
    {
      // Generate file and add all classes to it then save it
      $file = new PhpFile($this->service->getIdentifier());

      if ($useNamespace)
      {
        $file->addNamespace($this->config->getNamespaceName());
      }

      $file->addClass($this->service);

      foreach ($this->types as $class)
      {
        $file->addClass($class);
      }

      $file->save($outputDirectory);
    }
    else
    {
      // Save types
      foreach ($this->types as $class)
      {
        $file = new PhpFile($class->getIdentifier());

        if ($useNamespace)
        {
          $file->addNamespace($this->config->getNamespaceName());
        }

        $file->addClass($class);

        $file->save($outputDirectory);

        // Add the filename as dependency for the service
        $this->service->addDependency($class->getIdentifier().'.php');
      }

      // Generate file and save the service class
      $file = new PhpFile($this->service->getIdentifier());

      if ($useNamespace)
      {
        $file->addNamespace($this->config->getNamespaceName());
      }

      $file->addClass($this->service);

      $file->save($outputDirectory);
    }
  }
}