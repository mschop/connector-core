<?php
/**
 * @copyright 2010-2013 JTL-Software GmbH
 * @package jtl\Connector\Application
 */
namespace jtl\Connector\Application;

use jtl\Connector\Application\Error\ErrorHandler;
use jtl\Connector\Application\Error\IErrorHandler;
use jtl\Connector\Authentication\ITokenValidator;
use jtl\Connector\Compression\Zip;
use jtl\Connector\IO\Temp;
use jtl\Connector\Serializer\Json;
use jtl\Connector\Exception\RpcException;
use jtl\Connector\Exception\SessionException;
use jtl\Connector\Exception\ApplicationException;
use jtl\Connector\Rpc\Packet;
use jtl\Connector\Rpc\RequestPacket;
use jtl\Connector\Rpc\ResponsePacket;
use jtl\Connector\Rpc\Error;
use jtl\Connector\Http\Request;
use jtl\Connector\Http\Response;
use jtl\Connector\Config\Config;
use jtl\Connector\Exception\JsonException;
use jtl\Connector\Model\BoolResult;
use jtl\Connector\Result\Action;
use jtl\Connector\Database\Sqlite3;
use jtl\Connector\Utilities\RpcMethod;
use jtl\Connector\Session\Session;
use jtl\Connector\Base\Connector;
use jtl\Connector\Logger\Logger;
use Doctrine\Common\Annotations\AnnotationRegistry;
use jtl\Connector\Rpc\Method;
use jtl\Connector\Linker\IdentityLinker;
use jtl\Connector\Model\DataModel;
use Doctrine\Common\Collections\ArrayCollection;
use jtl\Connector\Serializer\JMS\SerializerBuilder;
use jtl\Connector\Linker\ChecksumLinker;
use Symfony\Component\EventDispatcher\EventDispatcher;
use jtl\Connector\IO\Path;
use jtl\Connector\Event\EventHandler;
use Symfony\Component\Finder\Finder;

/**
 * Application Class
 *
 * @access public
 * @author Daniel Böhmer <daniel.boehmer@jtl-software.de>
 */
class Application implements IApplication
{
    const PROTOCOL_VERSION = 7;
    
    /**
     * Connected EndpointConnectors
     *
     * @var IEndpointConnector
     */
    protected $connector = null;
    
    /**
     * @var Config;
     */
    protected $config;
    
    /**
     * Global Session
     *
     * @var \jtl\Connector\Session\Session
     */
    protected $session;
    
    /**
     * @var \Symfony\Component\EventDispatcher\EventDispatcher
     */
    protected $eventDispatcher;
    
    /**
     * @var \jtl\Connector\Application\Error\IErrorHandler
     */
    protected $errorHandler;
    
    protected function __construct()
    {
        require_once(dirname(__FILE__) . '/../bootstrap.php');
        
        // Error Handler
        $this->setErrorHandler(new ErrorHandler());
    }
    
    /**
     * (non-PHPdoc)
     * @see \jtl\Connector\Application\Application::run()
     */
    public function run(): void
    {
        AnnotationRegistry::registerLoader('class_exists');
        
        if ($this->connector === null) {
            throw new ApplicationException('No connector registed');
        }
        
        // Event Dispatcher
        $this->eventDispatcher = new EventDispatcher();
        
        $this->getErrorHandler()->setEventDispatcher($this->eventDispatcher);
        
        $jtlrpc = Request::handle($this->connector->getUseSuperGlobals());
        $sessionId = Request::getSession();
        $requestpackets = RequestPacket::build($jtlrpc);
        
        $rpcmode = is_object($requestpackets) ? Packet::SINGLE_MODE : Packet::BATCH_MODE;
        
        $method = null;
        if ($rpcmode == Packet::SINGLE_MODE) {
            $method = $requestpackets->getMethod();
        }
        
        // Start Session
        $this->startSession($sessionId, $method);
        
        // Start Configuration
        $this->startConfiguration();
        
        //Mask connector token before logging
        $reqPacketsObj = $requestpackets->getPublic();
        if (isset($reqPacketsObj->method) && $reqPacketsObj->method === 'core.connector.auth' && isset($reqPacketsObj->params)) {
            $params = Json::decode($reqPacketsObj->params, true);
            if (isset($params['token'])) {
                $params['token'] = str_repeat('*', strlen($params['token']));
            }
            $reqPacketsObj->params = Json::encode($params);
        }
        
        // Log incoming request packet (debug only and configuration must be initialized)
        Logger::write(sprintf('RequestPacket: %s', Json::encode($reqPacketsObj)), Logger::DEBUG, 'rpc');
        if (isset($reqPacketsObj->params) && !empty($reqPacketsObj->params)) {
            Logger::write(sprintf('Params: %s', $reqPacketsObj->params), Logger::DEBUG, 'rpc');
        }
        
        // Register Event Dispatcher
        $this->startEventDispatcher();
        
        // Initialize Endpoint
        $this->connector->initialize();
        
        if ($this->connector->getPrimaryKeyMapper() === null) {
            throw new ApplicationException('No primary key mapper registered');
        }
        
        $tokenValidatorExists = false;
        if (is_callable([
                $this->connector,
                'getTokenValidator',
            ]) && $this->connector->getTokenValidator() instanceof ITokenValidator) {
            $tokenValidatorExists = true;
        }
        
        if (is_null($this->connector->getTokenLoader()) && !$tokenValidatorExists) {
            throw new ApplicationException('Neither a token loader nor a token validator is registered');
        }
        
        if ($this->connector->getChecksumLoader() === null) {
            throw new ApplicationException('No checksum loader registered');
        }
        
        ChecksumLinker::setChecksumLoader($this->connector->getChecksumLoader());
        
        switch ($rpcmode) {
            case Packet::SINGLE_MODE:
                $this->runSingle($requestpackets, $rpcmode);
                break;
            case Packet::BATCH_MODE:
                $this->runBatch($requestpackets, $rpcmode);
                break;
        }
    }
    
    /**
     * @param RequestPacket $requestpacket
     * @param int $rpcmode
     * @param array $imagePaths
     * @return ResponsePacket
     * @throws ApplicationException
     * @throws RpcException
     * @throws \jtl\Connector\Exception\LinkerException
     */
    protected function execute(RequestPacket $requestpacket, int $rpcmode, array $imagePaths = []): ResponsePacket
    {
        if (!RpcMethod::isMethod($requestpacket->getMethod())) {
            throw new RpcException('Invalid Request', -32600);
        }
        
        $identityLinker = IdentityLinker::getInstance();
        $identityLinker->setPrimaryKeyMapper($this->connector->getPrimaryKeyMapper());
        
        ////////////////////
        // Core Connector //
        ////////////////////
        $coreconnector = Connector::getInstance();
        $method = RpcMethod::splitMethod($requestpacket->getMethod());
        $coreconnector->setMethod($method);
        
        // Rpc Event
        $data = $requestpacket->getParams();
        EventHandler::dispatchRpc($data, $this->eventDispatcher, $method->getController(), $method->getAction(),
            EventHandler::BEFORE);
        
        if ($method->isCore() && $coreconnector->canHandle()) {
            $actionresult = $coreconnector->handle($requestpacket);
            if ($actionresult->isHandled()) {
                $responsepacket = $this->buildRpcResponse($requestpacket, $actionresult);
                
                if ($rpcmode == Packet::SINGLE_MODE) {
                    
                    // Event
                    $class = ($method->getController() === 'connector') ? 'Connector' : null;
                    $res = $actionresult->getResult();
                    EventHandler::dispatch($res, $this->eventDispatcher, $method->getAction(), EventHandler::AFTER,
                        $class, true);
                    
                    $this->triggerRpcAfterEvent($responsepacket->getPublic(), $requestpacket->getMethod());
                    Response::send($responsepacket);
                } else {
                    return $responsepacket;
                }
            }
        }
        
        ////////////////////////
        // Endpoint Connector //
        ////////////////////////
        $exists = false;
        
        $this->deserializeRequestParams($requestpacket, $this->connector->getModelNamespace());
        
        // Image push?
        // OLD single Image
        //$this->handleImagePush($requestpacket, $imagePath);
        $this->handleImagePush($requestpacket, $imagePaths);
        
        $this->connector->setMethod($method);
        if ($this->connector->canHandle()) {
            /** @var Action $actionresult */
            $actionresult = $this->connector->handle($requestpacket);
            
            if ($requestpacket->getMethod() === 'image.push' && count($imagePaths) > 0) {
                Request::deleteFileuploads($imagePaths);
            }
            
            if ($actionresult instanceof Action) {
                $exists = true;
                if ($actionresult->isHandled()) {
                    
                    if ($actionresult->getError() === null) {
                        
                        // Convert boolean to BoolResult
                        if (is_bool($actionresult->getResult())) {
                            $actionresult->setResult((new BoolResult())->setResult($actionresult->getResult()));
                        }
                        
                        // Identity mapping
                        $results = [];
                        $models = is_array($actionresult->getResult()) ? $actionresult->getResult() : [$actionresult->getResult()];
                        
                        foreach ($models as $model) {
                            if ($model instanceof DataModel) {
                                $identityLinker->linkModel($model, ($method->getAction() === Method::ACTION_DELETE));
                                
                                // @TODO: Specific identity delete
                                
                                // Checksum linking
                                ChecksumLinker::link($model);
                                
                                // Event
                                $class = ($method->getController() === 'connector') ? 'Connector' : null;
                                EventHandler::dispatch($model, $this->eventDispatcher, $method->getAction(),
                                    EventHandler::AFTER, $class);
                                
                                if ($method->getAction() === Method::ACTION_PULL) {
                                    $results[] = $model->getPublic();
                                }
                            }
                        }
                        
                        if ($method->getAction() === Method::ACTION_PULL) {
                            $actionresult->setResult($results);
                        }
                    }
                    
                    // Building response packet
                    $responsepacket = $this->buildRpcResponse($requestpacket, $actionresult);
                    
                    if ($rpcmode == Packet::SINGLE_MODE) {
                        $this->triggerRpcAfterEvent($responsepacket->getPublic(), $requestpacket->getMethod());
                        Response::send($responsepacket);
                    } else {
                        return $responsepacket;
                    }
                }
            } else {
                throw new RpcException('Internal error', -32603);
            }
        } else {
            /*
             * OLD single Image
            if ($requestpacket->getMethod() === 'image.push') {
                Request::deleteFileupload($imagePath);
            }
            */
            if ($requestpacket->getMethod() === 'image.push' && count($imagePaths) > 0) {
                Request::deleteFileuploads($imagePaths);
            }
        }
        
        if ($exists) {
            throw new RpcException('Method could not be handled', -32000);
        } else {
            throw new RpcException(
                sprintf("Method '%s' not found", $requestpacket->getMethod()),
                -32601
            );
        }
    }
    
    /**
     * @param IEndpointConnector $endpointconnector
     */
    public function register(IEndpointConnector $endpointconnector): void
    {
        $this->connector = $endpointconnector;
    }

    /**
     * @param RequestPacket $requestpacket
     * @param int $rpcmode
     * @throws ApplicationException
     * @throws RpcException
     * @throws \jtl\Connector\Exception\CompressionException
     * @throws \jtl\Connector\Exception\HttpException
     */
    protected function runSingle(RequestPacket $requestpacket, int $rpcmode): void
    {
        $requestpacket->validate();

        $imagePaths = [];
        if ($requestpacket->getMethod() === 'image.push') {
            $zipFile = Request::handleFileupload();
            $tempDir = Temp::generateDirectory();
            
            if ($zipFile !== null && $tempDir !== null) {
                $archive = new Zip();
                if ($archive->extract($zipFile, $tempDir)) {
                    $finder = new Finder();
                    $finder->files()->ignoreDotFiles(true)->in($tempDir);
                    foreach ($finder as $file) {
                        $imagePaths[] = $file->getRealpath();
                    }
                } else {
                    @rmdir($tempDir);
                    @unlink($zipFile);
                    
                    throw new ApplicationException(sprintf('Zip File (%s) count not be extracted', $zipFile));
                }
                
                if ($zipFile !== null) {
                    @unlink($zipFile);
                }
            } else {
                throw new ApplicationException('Zip file or temp dir  is null');
            }
        }
        
        try {
            // OLD single Image
            //$this->execute($requestpacket, $config, $rpcmode, $imagePath);
            $this->execute($requestpacket, $rpcmode, $imagePaths);
        } catch (\Exception $exc) {
            /*
             * OLD single Image
            if ($requestpacket->getMethod() === 'image.push' && $imagePath !== null) {
                Request::deleteFileupload($imagePath);
            }
            */
            if ($requestpacket->getMethod() === 'image.push' && count($imagePaths) > 0) {
                Request::deleteFileuploads($imagePaths);
            }
            
            $error = new Error();
            $error->setCode($exc->getCode())
                ->setMessage($exc->getMessage());
            
            $responsepacket = new ResponsePacket();
            $responsepacket->setId($requestpacket->getId())
                ->setJtlrpc($requestpacket->getJtlrpc())
                ->setError($error);
            
            $this->triggerRpcAfterEvent($responsepacket->getPublic(), $requestpacket->getMethod());
            Response::send($responsepacket);
        }
    }

    /**
     * @param array $requestpackets
     * @param int $rpcmode
     * @throws ApplicationException
     * @throws \jtl\Connector\Exception\LinkerException
     */
    protected function runBatch(array $requestpackets, int $rpcmode): void
    {
        $jtlrpcreponses = [];
        
        foreach ($requestpackets as $requestpacket) {
            try {
                $requestpacket->validate();
                $jtlrpcreponses[] = $this->execute($requestpacket, $rpcmode);
            } catch (RpcException $exc) {
                $error = new Error();
                $error->setCode($exc->getCode())
                    ->setMessage($exc->getMessage());
                
                $responsepacket = new ResponsePacket();
                $responsepacket->setId($requestpacket->getId())
                    ->setJtlrpc($requestpacket->getJtlrpc())
                    ->setError($error);
                
                $jtlrpcreponses[] = $responsepacket;
            }
        }
        
        Response::sendAll($jtlrpcreponses);
    }
    
    /**
     * @param RequestPacket $requestpacket
     * @param string $modelNamespace
     * @return void
     * @throws \jtl\Connector\Exception\LinkerException
     */
    protected function deserializeRequestParams(RequestPacket &$requestpacket, string $modelNamespace): void
    {
        $method = RpcMethod::splitMethod($requestpacket->getMethod());
        $modelClass = RpcMethod::buildController($method->getController());
        
        $namespace = ($method->getAction() === Method::ACTION_PUSH || $method->getAction() === Method::ACTION_DELETE) ?
            sprintf('%s\%s', $modelNamespace, $modelClass) : 'jtl\Connector\Model\QueryFilter';
        
        if (class_exists("\\{$namespace}") && $requestpacket->getParams() !== null) {
            $serializer = SerializerBuilder::create();
            
            if ($method->getAction() === Method::ACTION_PUSH || $method->getAction() === Method::ACTION_DELETE) {
                // OLD single Image
                //$ns = ($method->getAction() === Method::ACTION_PUSH && $method->getController() === 'image') ? $namespace : "ArrayCollection<{$namespace}>";
                $ns = "ArrayCollection<{$namespace}>";
                $params = $serializer->deserialize($requestpacket->getParams(), $ns, 'json');
                
                $identityLinker = IdentityLinker::getInstance();
                if (is_array($params)) {
                    // Identity mapping
                    foreach ($params as &$param) {
                        $identityLinker->linkModel($param);
                        
                        // Checksum linking
                        ChecksumLinker::link($param);
                        
                        // Event
                        EventHandler::dispatch($param, $this->eventDispatcher, $method->getAction(),
                            EventHandler::BEFORE);
                    }
                } else {
                    $identityLinker->linkModel($params);
                    
                    // Checksum linking
                    ChecksumLinker::link($params);
                    
                    // Event
                    EventHandler::dispatch($params, $this->eventDispatcher, $method->getAction(), EventHandler::BEFORE);
                }
            } else {
                $params = $serializer->deserialize($requestpacket->getParams(), $namespace, 'json');
                
                // Event
                EventHandler::dispatch($params, $this->eventDispatcher, $method->getAction(), EventHandler::BEFORE,
                    $modelClass);
            }
            
            $requestpacket->setParams($params);
        }
    }
    
    /**
     * Build RPC Reponse Packet
     *
     * @param RequestPacket $requestpacket
     * @param Action $actionresult
     * @return ResponsePacket
     * @throws RpcException
     */
    protected function buildRpcResponse(RequestPacket $requestpacket, Action $actionresult): ResponsePacket
    {
        $responsepacket = new ResponsePacket();
        $responsepacket->setId($requestpacket->getId())
            ->setJtlrpc($requestpacket->getJtlrpc())
            ->setResult($actionresult->getResult())
            ->setError($actionresult->getError());
        
        $responsepacket->validate();
        
        return $responsepacket;
    }

    /**
     * Initialises the connector configuration instance.
     */
    protected function startConfiguration(): void
    {
        if (!isset($this->session)) {
            throw new SessionException('Session not initialized', -32001);
        }
        
        // Config
        if (is_null($this->config)) {
            $config_file = Path::combine(CONNECTOR_DIR, 'config', 'config.json');
            if (!file_exists($config_file)) {
                $json = json_encode(['developer_logging' => false], JSON_PRETTY_PRINT);
                
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw JsonException::encoding(json_last_error_msg());
                }
                
                file_put_contents($config_file, $json);
            }
            
            $this->config = new Config($config_file);
        }
        
        if (!$this->config->has('developer_logging')) {
            $this->config->save('developer_logging', false);
        }
    }
    
    /**
     * Starting Session
     *
     * @param null $sessionId
     * @param $method
     * @throws ApplicationException
     * @throws SessionException
     * @throws \jtl\Connector\Exception\DatabaseException
     */
    protected function startSession($sessionId = null, string $method): void
    {
        if ($sessionId === null && $method !== null && $method !== 'core.connector.auth') {
            throw new SessionException('No session');
        }
        
        $dir = Path::combine(CONNECTOR_DIR, 'db');
        if (!is_dir($dir)) {
            if (!mkdir($dir)) {
                throw new ApplicationException('Could not create sqlite database directory');
            }
        }
        
        $sqlite3 = Sqlite3::getInstance();
        $sqlite3->connect(['location' => Path::combine($dir, 'connector.s3db')]);
        $sqlite3->check();
        
        $this->session = new Session($sqlite3, $sessionId);
    }
    
    /**
     *
     */
    protected function startEventDispatcher(): void
    {
        $this->connector->setEventDispatcher($this->eventDispatcher);
        
        $loader = new \jtl\Connector\Plugin\PluginLoader();
        $loader->load($this->eventDispatcher);
    }

    /**
     * @param RequestPacket $requestpacket
     * @param array $imagePaths
     * @throws ApplicationException
     */
    protected function handleImagePush(RequestPacket &$requestpacket, array $imagePaths = []): void
    {
        if ($requestpacket->getMethod() === 'image.push') {
            $images = $requestpacket->getParams();
            if (!is_array($images)) {
                throw new ApplicationException('Request params must be valid images');
            }
            
            if (count($imagePaths) > 0) {
                for ($i = 0; $i < count($images); $i++) {
                    foreach ($imagePaths as $imagePath) {
                        $infos = pathinfo($imagePath);
                        list ($hostId, $relationType) = explode('_', $infos['filename']);
                        if ((int)$hostId == $images[$i]->getId()->getHost()
                            && strtolower($relationType) === strtolower($images[$i]->getRelationType())
                        ) {
                            $images[$i]->setFilename($imagePath);
                        }
                    }
                }
                
                $requestpacket->setParams($images);
            } else {
                for ($i = 0; $i < count($images); $i++) {
                    if (strlen($images[$i]->getRemoteUrl()) > 0) {
                        $imageData = file_get_contents($images[$i]->getRemoteUrl());
                        if ($imageData === false) {
                            throw new ApplicationException('Could not get any data from url: ' . $images[$i]->getRemoteUrl());
                        }
                        
                        $path = parse_url($images[$i]->getRemoteUrl(), PHP_URL_PATH);
                        $fileName = pathinfo($path, PATHINFO_BASENAME);
                        $imagePath = Path::combine(Temp::getDirectory(), uniqid() . "_{$fileName}");
                        file_put_contents($imagePath, $imageData);
                        
                        $images[$i]->setFilename($imagePath);
                    } else {
                        throw new ApplicationException('Could not handle fileupload (no file was uploaded via HTTP POST?)');
                    }
                }
                
                $requestpacket->setParams($images);
            }
        }
    }
    
    /**
     * @param \stdClass $data
     * @param string $method
     */
    protected function triggerRpcAfterEvent(\stdClass $data, string $method): void
    {
        $method = RpcMethod::splitMethod($method);
        EventHandler::dispatchRpc($data, $this->eventDispatcher, $method->getController(), $method->getAction(),
            EventHandler::AFTER);
    }
    
    /**
     * Connector getter
     *
     * @return IEndpointConnector
     */
    public function getConnector(): ?IEndpointConnector
    {
        return $this->connector;
    }
    
    /**
     * Session getter
     *
     * @return Session
     */
    public function getSession(): ?Session
    {
        return $this->session;
    }
    
    /**
     * @return int
     */
    public function getProtocolVersion(): int
    {
        return self::PROTOCOL_VERSION;
    }
    
    /**
     * @return Config
     */
    public function getConfig(): ?Config
    {
        return $this->config;
    }
    
    /**
     * @return IErrorHandler
     */
    public function getErrorHandler(): ?IErrorHandler
    {
        return $this->errorHandler;
    }
    
    /**
     * @param IErrorHandler $handler
     * @return $this
     */
    public function setErrorHandler(IErrorHandler $handler): Application
    {
        $this->errorHandler = $handler;
        
        return $this;
    }
}
