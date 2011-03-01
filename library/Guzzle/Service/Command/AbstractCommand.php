<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Command;

use Guzzle\Common\Filter\Chain;
use Guzzle\Common\Inspector;
use Guzzle\Common\Collection;
use Guzzle\Common\NullObject;
use Guzzle\Http\Message\Response;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Service\ApiCommand;
use Guzzle\Service\Client;

/**
 * Command object to handle preparing and processing client requests and
 * responses of the requests
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
abstract class AbstractCommand extends Collection implements CommandInterface
{
    /**
     * @var Client The client object used to execute the command
     */
    protected $client;

    /**
     * @var RequestInterface The request object associated with the command
     */
    protected $request;

    /**
     * @var mixed The result of the command
     */
    protected $result;

    /**
     * @var bool Whether or not the command can be batched
     */
    protected $canBatch = true;

    /**
     * @var ApiCommand API information about the command
     */
    protected $apiCommand;

    /**
     * Constructor
     *
     * @param array|Collection $parameters (optional) Collection of parameters
     *      to set on the command
     */
    public function __construct($parameters = null, ApiCommand $apiCommand = null)
    {
        parent::__construct($parameters);

        $this->apiCommand = $apiCommand;

        // Add arguments and validate the command
        if ($this->apiCommand) {
            Inspector::getInstance()->validateConfig($apiCommand->getArgs(), $this, false);
        } else if (!($this instanceof ClosureCommand)) {
            Inspector::getInstance()->validateClass(get_class($this), $this, false);
        }

        if (!$this->get('headers') instanceof Collection) {
            $this->set('headers', new Collection());
        }
        
        $this->init();
    }

    /**
     * Get the API command information about the command
     *
     * @return ApiCommand|NullObject
     */
    public function getApiCommand()
    {
        return $this->apiCommand ?: new NullObject();
    }

    /**
     * Set the API command associated with the command
     *
     * @param ApiCommand $apiCommand API command information
     *
     * @return AbstractCommand
     */
    public function setApiCommand(ApiCommand $apiCommand)
    {
        $this->apiCommand = $apiCommand;

        return $this;
    }

    /**
     * Get whether or not the command can be batched
     *
     * @return bool
     */
    public function canBatch()
    {
        return $this->canBatch;
    }

    /**
     * Execute the command
     *
     * @return Command
     * @throws CommandException if a client has not been associated with the command
     */
    public function execute()
    {
        if (!$this->client) {
            throw new CommandException('A Client object must be associated with the command before it can be executed from the context of the command.');
        }

        $this->client->execute($this);
        
        return $this;
    }

    /**
     * Get the client object that will execute the command
     *
     * @return Client|null
     */
    public function getClient()
    {
        return $this->client;
    }
    
    /**
     * Set the client objec that will execute the command
     *
     * @param Client $client The client objec that will execute the command
     *
     * @return Command
     */
    public function setClient(Client $client)
    {
        $this->client = $client;

        return $this;
    }

    /**
     * Get the request object associated with the command
     *
     * @return RequestInterface
     * @throws CommandException if the command has not been executed
     */
    public function getRequest()
    {
        if (!$this->request) {
            throw new CommandException('The command must be prepared before retrieving the request');
        }
        
        return $this->request;
    }

    /**
     * Get the response object associated with the command
     *
     * @return Response
     * @throws CommandException if the command has not been executed
     */
    public function getResponse()
    {
        if (!$this->isExecuted()) {
            throw new CommandException('The command must be executed before retrieving the response');
        }
        
        return $this->request->getResponse();
    }

    /**
     * Get the result of the command
     *
     * @return Response By default, commands return a Response
     *      object unless overridden in a subclass
     * @throws CommandException if the command has not been executed
     */
    public function getResult()
    {
        if (!$this->isExecuted()) {
            throw new CommandException('The command must be executed before retrieving the result');
        }
        
        if (is_null($this->result)) {
            $this->process();
        }

        return $this->result;
    }

    /**
     * Returns TRUE if the command has been prepared for executing
     *
     * @return bool
     */
    public function isPrepared()
    {
        return !is_null($this->request);
    }

    /**
     * Returns TRUE if the command has been executed
     *
     * @return bool
     */
    public function isExecuted()
    {
        return !is_null($this->request) && $this->request->getState() == 'complete';
    }

    /**
     * Prepare the command for executing.
     *
     * Create a request object for the command.
     *
     * @param Client $client (optional) The client object used to execute the command
     *
     * @return RequestInterface Returns the generated request
     * @throws CommandException if a client object has not been set previously
     *      or in the prepare()
     */
    public function prepare(Client $client = null)
    {
        if (!$this->isPrepared()) {
            if ($client) {
                $this->client = $client;
            }

            if (!$this->client) {
                throw new CommandException('A Client object must be associated with the command before it can be prepared.');
            }

            // Fail on missing required arguments when it is not a ClosureCommand
            if (!($this instanceof ClosureCommand)) {
                if ($this->getApiCommand() instanceof NullObject) {
                    Inspector::getInstance()->validateClass(get_class($this), $this, true);
                } else {
                    Inspector::getInstance()->validateConfig($this->getApiCommand()->getArgs(), $this);
                }
            }
            
            $this->build();

            // Add custom request headers set on the command
            if ($this->hasKey('headers') && $this->get('headers') instanceof Collection) {
                foreach ($this->get('headers') as $key => $value) {
                    $this->request->setHeader($key, $value);
                }
            }
        }

        return $this->getRequest();
    }

    /**
     * Set an HTTP header on the outbound request
     *
     * @param string $header The name of the header to set
     * @param string $value The value to set on the header
     *
     * @return AbstractCommand
     */
    public function setRequestHeader($header, $value)
    {
        $this->get('headers')->set($header, $value);

        return $this;
    }

    /**
     * Get the object that manages the request headers
     *
     * @return Collection
     */
    public function getRequestHeaders()
    {
        return ($this->request) ? $this->request->getHeaders() : $this->get('headers');
    }

    /**
     * Initialize the command (hook to be implemented in subclasses)
     */
    protected function init()
    {
        return;
    }

    /**
     * Create the request object that will carry out the command
     */
    abstract protected function build();

    /**
     * Create the result of the command after the request has been completed.
     *
     * Sets the result as the response by default.  If the response is an XML
     * document, this will set the result as a SimpleXMLElement.  If the XML
     * response is invalid, the result will remain the Response, not XML.
     */
    protected function process()
    {
        $this->result = $this->getRequest()->getResponse();

        // Is the body an XML document?  If so, set the result to be a SimpleXMLElement
        if (preg_match('/^\s*(text\/xml|application\/xml).*$/', $this->result->getContentType())) {
            // If the body is available, then parse the XML
            $body = trim($this->result->getBody(true));
            if ($body) {
                // Silently allow parsing the XML to fail
                try {
                    $xml = new \SimpleXMLElement($body);
                    $this->result = $xml;
                } catch (\Exception $e) {}
            }
        }
    }
}