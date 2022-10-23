<?php

namespace ZipkinBundle\Tests\Unit;

use Symfony\Component\HttpKernel\Kernel;
use Zipkin\TracingBuilder;
use Zipkin\Samplers\BinarySampler;
use Zipkin\Reporters\InMemory;
use Zipkin\Reporters\InMemory as InMemoryReporter;
use Zipkin\Reporter;
use Zipkin\Instrumentation\Http\Server\HttpServerTracing;
use ZipkinBundle\RouteMapper\RouteMapper;
use ZipkinBundle\KernelListener;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\KernelEvent;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Psr\Log\NullLogger;
use PHPUnit\Framework\TestCase;
use Exception;

final class KernelListenerTest extends TestCase
{
    const HTTP_HOST = 'localhost';
    const HTTP_METHOD = 'OPTIONS';
    const HTTP_PATH = '/foo';
    const TAG_KEY = 'key';
    const TAG_VALUE = 'value';
    const EXCEPTION_MESSAGE = 'message';

    public static function createHttpServerTracing(): array
    {
        $reporter = new InMemoryReporter();
        $tracing = TracingBuilder::create()
            ->havingSampler(BinarySampler::createAsAlwaysSample())
            ->havingReporter($reporter)
            ->build();

        return [
            new HttpServerTracing($tracing),
            static function () use ($reporter, $tracing): array {
                $tracing->getTracer()->flush();
                return $reporter->flush();
            },
            $reporter
        ];
    }

    public function testSpanIsNotCreatedOnNonMainRequest()
    {
        /**
         * @var HttpServerTracing $httpServerTracing
         */
        list($httpServerTracing) = self::createHttpServerTracing();

        $kernelListener = new KernelListener($httpServerTracing, RouteMapper::createAsNoop());

        $event = $this->prophesize(KernelEvent::class);
        $event->isMainRequest()->willReturn(false);
        $kernelListener->onKernelRequest($event->reveal());

        $this->assertNull(
            $httpServerTracing->getTracing()->getTracer()->getCurrentSpan()
        );
    }

    public function testSpanIsCreatedOnKernelRequest()
    {
        /**
         * @var HttpServerTracing $httpServerTracing
         */
        list($httpServerTracing, $flusher) = self::createHttpServerTracing();

        $logger = new NullLogger();

        $kernelListener = new KernelListener(
            $httpServerTracing,
            RouteMapper::createAsNoop(),
            $logger,
            [self::TAG_KEY => self::TAG_VALUE]
        );

        $request = new Request([], [], [], [], [], [
            'REQUEST_METHOD' => self::HTTP_METHOD,
            'REQUEST_URI' => self::HTTP_PATH,
            'HTTP_HOST' => self::HTTP_HOST,
        ]);

        $event = $this->prophesize(KernelEvent::class);
        $event->isMainRequest()->willReturn(true);
        $event->getRequest()->willReturn($request);

        $kernelListener->onKernelRequest($event->reveal());

        $spans = $flusher();
        $this->assertCount(1, $spans);
        $this->assertEquals([
            'http.method' => self::HTTP_METHOD,
            'http.path' => self::HTTP_PATH,
            self::TAG_KEY => self::TAG_VALUE,
        ], $spans[0]->getTags());
    }

    private function mockKernel()
    {
        return $this->prophesize(HttpKernelInterface::class)->reveal();
    }

    public function testNoSpanIsTaggedOnKernelExceptionIfItIsNotStarted()
    {
        /**
         * @var HttpServerTracing $httpServerTracing
         * @var callable $flusher
         */
        list($httpServerTracing, $flusher) = self::createHttpServerTracing();

        $kernelListener = new KernelListener($httpServerTracing);

        $event = $this->prophesize(KernelEvent::class);
        $event->isMainRequest()->willReturn(false);
        $event->getRequest()->willReturn(new Request());

        $kernelListener->onKernelRequest($event->reveal());

        $exceptionEvent = new ExceptionEvent(
            $this->mockKernel(),
            new Request(),
            HttpKernelInterface::SUB_REQUEST, // isMainRequest will be false
            new Exception()
        );

        $kernelListener->onKernelException($exceptionEvent);

        $spans = $flusher();
        $this->assertCount(0, $spans);
    }

    public function testSpanIsTaggedOnKernelException()
    {
        /**
         * @var HttpServerTracing $httpServerTracing
         * @var callable $flusher
         */
        list($httpServerTracing, $flusher) = self::createHttpServerTracing();
        $logger = new NullLogger();
        $kernelListener = new KernelListener($httpServerTracing, RouteMapper::createAsNoop(), $logger);

        $eventRequest = new Request();
        $event = $this->prophesize(KernelEvent::class);
        $event->isMainRequest()->willReturn(true);
        $event->getRequest()->willReturn($eventRequest);

        $kernelListener->onKernelRequest($event->reveal());

        $exceptionEvent = new ExceptionEvent(
            $this->mockKernel(),
            $eventRequest,
            HttpKernelInterface::MAIN_REQUEST,
            new Exception(self::EXCEPTION_MESSAGE)
        );

        $kernelListener->onKernelException($exceptionEvent);

        $spans = $flusher();
        $this->assertCount(1, $spans);

        $this->assertEquals($exceptionEvent->getThrowable(), $spans[0]->getError());
    }

    public function testNoSpanIsTaggedOnKernelTerminateIfItIsNotStarted()
    {
        /**
         * @var HttpServerTracing $httpServerTracing
         * @var callable $flusher
         * @var InMemory $reporter
         */
        list($httpServerTracing, $flusher, $reporter) = self::createHttpServerTracing();
        $logger = new NullLogger();

        $kernelListener = new KernelListener($httpServerTracing, RouteMapper::createAsNoop(), $logger);

        $event = $this->prophesize(KernelEvent::class);
        $event->isMainRequest()->willReturn(false);
        $event->getRequest()->willReturn(new Request());

        $kernelListener->onKernelRequest($event->reveal());

        $terminateEvent = new TerminateEvent(
            $this->mockKernel(),
            new Request(),
            new Response()
        );

        $kernelListener->onKernelTerminate($terminateEvent);
        $spans = $reporter->flush();
        $this->assertCount(0, $spans);
    }

    public function statusCodeProvider()
    {
        return [
            [200],
            [300],
            [400],
            [500]
        ];
    }

    /**
     * @dataProvider statusCodeProvider
     */
    public function testSpanIsTaggedOnKernelResponse($responseStatusCode)
    {
        /**
         * @var HttpServerTracing $httpServerTracing
         * @var callable $flusher
         */
        list($httpServerTracing, $flusher) = self::createHttpServerTracing();

        $kernelListener = new KernelListener($httpServerTracing);

        $request = new Request([], [], [], [], [], [
            'REQUEST_METHOD' => self::HTTP_METHOD,
            'REQUEST_URI' => self::HTTP_PATH,
            'HTTP_HOST' => self::HTTP_HOST,
        ]);

        $event = $this->prophesize(KernelEvent::class);
        $event->isMainRequest()->willReturn(true);
        $event->getRequest()->willReturn($request);

        $kernelListener->onKernelRequest($event->reveal());

        $responseEvent = new ResponseEvent(
            $this->mockKernel(),
            $request,
            KernelInterface::MAIN_REQUEST,
            new Response('', $responseStatusCode)
        );

        $kernelListener->onKernelResponse($responseEvent);

        $assertTags = [
            'http.method' => self::HTTP_METHOD,
            'http.path' => self::HTTP_PATH,
        ];

        if ($responseStatusCode < 100 || $responseStatusCode > 299) {
            $assertTags['http.status_code'] = (string) $responseStatusCode;
        }

        if ($responseStatusCode > 399) {
            $assertTags['error'] = (string) $responseStatusCode;
        }

        $spans = $flusher();
        $this->assertCount(1, $spans);
        $this->assertEquals($assertTags, $spans[0]->getTags());
    }

    public function testSpanScopeIsClosedOnResponse()
    {
        /**
         * @var HttpServerTracing $httpServerTracing
         * @var callable $flusher
         */
        list($httpServerTracing) = self::createHttpServerTracing();
        $logger = new NullLogger();

        $kernelListener = new KernelListener($httpServerTracing, RouteMapper::createAsNoop(), $logger);

        $request = new Request();

        $event = $this->prophesize(KernelEvent::class);
        $event->isMainRequest()->willReturn(true);
        $event->getRequest()->willReturn($request);

        $kernelListener->onKernelRequest($event->reveal());

        $responseEvent = new ResponseEvent(
            $this->mockKernel(),
            $request,
            KernelInterface::MAIN_REQUEST,
            new Response()
        );

        $this->assertNotNull($httpServerTracing->getTracing()->getTracer()->getCurrentSpan());

        $kernelListener->onKernelResponse($responseEvent);

        $this->assertNull($httpServerTracing->getTracing()->getTracer()->getCurrentSpan());
    }

    /**
     * @dataProvider statusCodeProvider
     */
    public function testSpanIsTaggedOnKernelTerminate($responseStatusCode)
    {
        /**
         * @var HttpServerTracing $httpServerTracing
         * @var callable $flusher
         * @var InMemory $reporter
         */
        list($httpServerTracing, $flusher, $reporter) = self::createHttpServerTracing();
        $logger = new NullLogger();

        $kernelListener = new KernelListener($httpServerTracing, RouteMapper::createAsNoop(), $logger);

        $request = new Request([], [], [], [], [], [
            'REQUEST_METHOD' => self::HTTP_METHOD,
            'REQUEST_URI' => self::HTTP_PATH,
            'HTTP_HOST' => self::HTTP_HOST,
        ]);

        $event = $this->prophesize(KernelEvent::class);
        $event->isMainRequest()->willReturn(true);
        $event->getRequest()->willReturn($request);

        $kernelListener->onKernelRequest($event->reveal());

        $responseEvent = new TerminateEvent(
            $this->mockKernel(),
            $request,
            new Response('', $responseStatusCode)
        );

        $kernelListener->onKernelTerminate($responseEvent);

        $assertTags = [
            'http.method' => self::HTTP_METHOD,
            'http.path' => self::HTTP_PATH,
        ];

        if ($responseStatusCode < 100 || $responseStatusCode > 299) {
            $assertTags['http.status_code'] = (string) $responseStatusCode;
        }

        if ($responseStatusCode > 399) {
            $assertTags['error'] = (string) $responseStatusCode;
        }

        // There is no need to call `Tracer::flush` here as `onKernelTerminate` does
        // it already.
        $spans = $reporter->flush();
        $this->assertCount(1, $spans);
        $this->assertEquals($assertTags, $spans[0]->getTags());
    }

    public function testSpanScopeIsClosedOnTerminate()
    {
        /**
         * @var HttpServerTracing $httpServerTracing
         */
        list($httpServerTracing) = self::createHttpServerTracing();
        $logger = new NullLogger();

        $kernelListener = new KernelListener($httpServerTracing, RouteMapper::createAsNoop(), $logger);

        $request = new Request();

        $event = $this->prophesize(KernelEvent::class);
        $event->isMainRequest()->willReturn(true);
        $event->getRequest()->willReturn($request);

        $kernelListener->onKernelRequest($event->reveal());

        $responseEvent = new TerminateEvent(
            $this->mockKernel(),
            $request,
            new Response()
        );

        $this->assertNotNull($httpServerTracing->getTracing()->getTracer()->getCurrentSpan());

        $kernelListener->onKernelTerminate($responseEvent);

        $this->assertNull($httpServerTracing->getTracing()->getTracer()->getCurrentSpan());
    }
}
