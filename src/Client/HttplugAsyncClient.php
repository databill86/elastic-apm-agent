<?php
declare(strict_types=1);

namespace TechDeCo\ElasticApmAgent\Client;

use Http\Client\HttpAsyncClient;
use Http\Discovery\Exception as DiscoveryException;
use Http\Discovery\HttpAsyncClientDiscovery;
use Http\Discovery\MessageFactoryDiscovery;
use Http\Message\MessageFactory;
use Http\Promise\Promise;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use TechDeCo\ElasticApmAgent\AsyncClient;
use TechDeCo\ElasticApmAgent\ClientConfiguration;
use TechDeCo\ElasticApmAgent\Exception\ClientException;
use TechDeCo\ElasticApmAgent\Request\Error;
use TechDeCo\ElasticApmAgent\Request\Transaction;
use function json_encode;

final class HttplugAsyncClient implements AsyncClient
{
    /**
     * @var ClientConfiguration
     */
    private $config;

    /**
     * @var HttpAsyncClient
     */
    private $httpClient;

    /**
     * @var MessageFactory
     */
    private $httpMessageFactory;

    /**
     * @var Promise[]
     */
    private $promiseList = [];

    /**
     * @throws DiscoveryException
     */
    public function __construct(
        ClientConfiguration $config,
        ?HttpAsyncClient $httpClient,
        ?MessageFactory $httpMessageFactory
    ) {
        $this->config             = $config;
        $this->httpClient         = $httpClient ?? HttpAsyncClientDiscovery::find();
        $this->httpMessageFactory = $httpMessageFactory ?? MessageFactoryDiscovery::find();
    }

    /**
     * @throws ClientException
     */
    public function sendTransactionAsync(Transaction $transaction): void
    {
        $request = $this->httpMessageFactory->createRequest(
            'POST',
            $this->config->getTransactionsEndpoint(),
            [],
            json_encode($transaction)
        );

        $this->sendRequest($request);
    }

    /**
     * @throws ClientException
     */
    public function sendErrorAsync(Error $error): void
    {
        $request = $this->httpMessageFactory->createRequest(
            'POST',
            $this->config->getErrorsEndpoint(),
            [],
            json_encode($error)
        );

        $this->sendRequest($request);
    }

    /**
     * @throws ClientException
     */
    public function waitForResponses(): void
    {
        $exceptionList = [];
        foreach ($this->promiseList as $promise) {
            try {
                /** @var ResponseInterface $response */
                $response = $promise->wait();
                $status   = $response->getStatusCode();

                if ($status >= 400 && $status < 500) {
                    throw ClientException::fromResponse('Bad request', $response);
                }
                if ($status >= 500) {
                    throw ClientException::fromResponse('APM internal server error', $response);
                }
            } catch (\Throwable $e) {
                $exceptionList[] = $e;
            }
        }

        if (! empty($exceptionList)) {
            throw ClientException::fromException('Encountered errors while resolving requests', ...$exceptionList);
        }
    }

    /**
     * @throws ClientException
     */
    private function sendRequest(RequestInterface $request): void
    {
        try {
            $request = $request->withHeader('Content-Type', 'application/json');
            if ($this->config->needsAuthentication()) {
                $request = $request->withHeader('Authorization', 'Bearer ' . $this->config->getToken());
            }

            $this->promiseList[] = $this->httpClient->sendAsyncRequest($request);
        } catch (\Throwable $e) {
            throw new ClientException('Could not send request due to configuration error', 0, $e);
        }
    }
}