<?php
declare(strict_types=1);

namespace Jadob\Core\Event;

use Jadob\Core\RequestContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * @author  pizzaminded <mikolajczajkowsky@gmail.com>
 * @license MIT
 */
class AfterControllerEvent
{
    /**
     * @var Response
     */
    protected $response;

    protected $context;

    /**
     * AfterControllerEvent constructor.
     *
     * @param Response $response
     */
    public function __construct(Response $response, RequestContext $context)
    {
        $this->response = $response;
        $this->context = $context;
    }

    /**
     * @return Response
     */
    public function getResponse(): Response
    {
        return $this->response;
    }

    /**
     * @param Response $response
     * @return AfterControllerEvent
     */
    public function setResponse(Response $response): self
    {
        $this->response = $response;
        return $this;
    }

    /**
     * @return RequestContext
     */
    public function getContext(): RequestContext
    {
        return $this->context;
    }
}