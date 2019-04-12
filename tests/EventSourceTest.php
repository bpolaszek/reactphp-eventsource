<?php

use PHPUnit\Framework\TestCase;
use Clue\React\EventSource\EventSource;
use React\Promise\Promise;
use React\Promise\Deferred;
use RingCentral\Psr7\Response;
use React\Stream\ThroughStream;
use Clue\React\Buzz\Message\ReadableBodyStream;
use Clue\React\Buzz\Browser;

class EventSourceTest extends TestCase
{
    /**
     * @expectedException InvalidArgumentException
     */
    public function testConstructorThrowsIfFirstArgumentIsNotAnUri()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        new EventSource('///', $loop);
    }

    /**
     * @expectedException InvalidArgumentException
     */
    public function testConstructorThrowsIfUriArgumentDoesNotIncludeScheme()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        new EventSource('example.com', $loop);
    }

    /**
     * @expectedException InvalidArgumentException
     */
    public function testConstructorThrowsIfUriArgumentIncludesInvalidScheme()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        new EventSource('ftp://example.com', $loop);
    }

    public function testConstructorCanBeCalledWithoutBrowser()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $es = new EventSource('http://example.com', $loop);

        $ref = new ReflectionProperty($es, 'browser');
        $ref->setAccessible(true);
        $browser = $ref->getValue($es);

        $this->assertInstanceOf('Clue\React\Buzz\Browser', $browser);
    }

    public function testConstructorWillStartTimerToStartRequest()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->once())->method('addTimer')->with(0, $this->isType('callable'));

        $browser = $this->getMockBuilder('Clue\React\Buzz\Browser')->disableOriginalConstructor()->getMock();
        $browser->expects($this->once())->method('withOptions')->willReturnSelf();
        $browser->expects($this->never())->method('get');

        new EventSource('http://example.com', $loop, $browser);
    }


    public function testConstructorWillSendGetRequestThroughGivenBrowserAfterTimer()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $timer = null;
        $loop->expects($this->once())->method('addTimer')->with(0, $this->callback(function ($cb) use (&$timer) {
            $timer = $cb;
            return true;
        }));

        $pending = new Promise(function () { });
        $browser = $this->getMockBuilder('Clue\React\Buzz\Browser')->disableOriginalConstructor()->getMock();
        $browser->expects($this->once())->method('withOptions')->willReturnSelf();
        $browser->expects($this->once())->method('get')->with('http://example.com')->willReturn($pending);

        $es = new EventSource('http://example.com', $loop, $browser);

        $this->assertNotNull($timer);
        $timer();
    }

    public function testConstructorWillSendGetRequestThroughGivenBrowserWithHttpsSchemeAfterTimer()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $timer = null;
        $loop->expects($this->once())->method('addTimer')->with(0, $this->callback(function ($cb) use (&$timer) {
            $timer = $cb;
            return true;
        }));

        $pending = new Promise(function () { });
        $browser = $this->getMockBuilder('Clue\React\Buzz\Browser')->disableOriginalConstructor()->getMock();
        $browser->expects($this->once())->method('withOptions')->willReturnSelf();
        $browser->expects($this->once())->method('get')->with('https://example.com')->willReturn($pending);

        $es = new EventSource('https://example.com', $loop, $browser);

        $this->assertNotNull($timer);
        $timer();
    }

    public function testCloseWillCancelPendingConnectionTimerBeforeInitialGetRequestWhenCalledDirectlyAfterConstruction()
    {
        $timer = $this->getMockBuilder('React\EventLoop\TimerInterface')->getMock();
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->once())->method('addTimer')->willReturn($timer);
        $loop->expects($this->once())->method('cancelTimer')->with($timer);

        $browser = $this->getMockBuilder('Clue\React\Buzz\Browser')->disableOriginalConstructor()->getMock();
        $browser->expects($this->once())->method('withOptions')->willReturnSelf();
        $browser->expects($this->never())->method('get');

        $es = new EventSource('http://example.com', $loop, $browser);
        $es->close();
    }

    public function testCloseAfterTimerWillCancelPendingGetRequest()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $timer = null;
        $loop->expects($this->once())->method('addTimer')->with(0, $this->callback(function ($cb) use (&$timer) {
            $timer = $cb;
            return true;
        }));

        $cancelled = null;
        $pending = new Promise(function () { }, function () use (&$cancelled) {
            ++$cancelled;
        });
        $browser = $this->getMockBuilder('Clue\React\Buzz\Browser')->disableOriginalConstructor()->getMock();
        $browser->expects($this->once())->method('withOptions')->willReturnSelf();
        $browser->expects($this->once())->method('get')->with('http://example.com')->willReturn($pending);

        $es = new EventSource('http://example.com', $loop, $browser);

        $this->assertNotNull($timer);
        $timer();

        $es->close();

        $this->assertEquals(1, $cancelled);
    }

    public function testCloseAfterTimerWillNotEmitErrorEventWhenGetRequestCancellationHandlerRejects()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $timer = null;
        $loop->expects($this->once())->method('addTimer')->with(0, $this->callback(function ($cb) use (&$timer) {
            $timer = $cb;
            return true;
        }));

        $pending = new Promise(function () { }, function () {
            throw new RuntimeException();
        });
        $browser = $this->getMockBuilder('Clue\React\Buzz\Browser')->disableOriginalConstructor()->getMock();
        $browser->expects($this->once())->method('withOptions')->willReturnSelf();
        $browser->expects($this->once())->method('get')->with('http://example.com')->willReturn($pending);

        $es = new EventSource('http://example.com', $loop, $browser);

        $this->assertNotNull($timer);
        $timer();

        $error = null;
        $es->on('error', function ($e) use (&$error) {
            $error = $e;
        });

        $es->close();

        $this->assertNull($error);
    }

    public function testConstructorWillStartGetRequestThatWillStartRetryTimerWhenGetRequestRejects()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $timer = null;
        $loop->expects($this->exactly(2))->method('addTimer')->withConsecutive(
            [
                0,
                $this->callback(function ($cb) use (&$timer) {
                    $timer = $cb;
                    return true;
                })
            ],
            [
                3.0,
                $this->isType('callable')
            ]
        );

        $deferred = new Deferred();
        $browser = $this->getMockBuilder('Clue\React\Buzz\Browser')->disableOriginalConstructor()->getMock();
        $browser->expects($this->once())->method('withOptions')->willReturnSelf();
        $browser->expects($this->once())->method('get')->willReturn($deferred->promise());

        $es = new EventSource('http://example.com', $loop, $browser);

        $this->assertNotNull($timer);
        $timer();

        $deferred->reject(new RuntimeException());
    }

    public function testConstructorWillStartGetRequestThatWillStartRetryTimerThatWillRetryGetRequestWhenInitialGetRequestRejects()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $timerStart = $timerRetry = null;
        $loop->expects($this->exactly(2))->method('addTimer')->withConsecutive(
            [
                0,
                $this->callback(function ($cb) use (&$timerStart) {
                    $timerStart = $cb;
                    return true;
                })
            ],
            [
                3.0,
                $this->callback(function ($cb) use (&$timerRetry) {
                    $timerRetry = $cb;
                    return true;
                })
            ]
        );

        $deferred = new Deferred();
        $browser = $this->getMockBuilder('Clue\React\Buzz\Browser')->disableOriginalConstructor()->getMock();
        $browser->expects($this->once())->method('withOptions')->willReturnSelf();
        $browser->expects($this->exactly(2))->method('get')->willReturnOnConsecutiveCalls(
            $deferred->promise(),
            new Promise(function () { })
        );

        $es = new EventSource('http://example.com', $loop, $browser);

        $this->assertNotNull($timerStart);
        $timerStart();

        $deferred->reject(new RuntimeException());

        $this->assertNotNull($timerRetry);
        $timerRetry();
    }

    public function testConstructorWillStartGetRequestThatWillEmitErrorWhenGetRequestRejects()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $timer = null;
        $loop->expects($this->exactly(2))->method('addTimer')->withConsecutive(
            [
                0,
                $this->callback(function ($cb) use (&$timer) {
                    $timer = $cb;
                    return true;
                })
            ],
            [
                3.0,
                $this->isType('callable')
            ]
        );

        $deferred = new Deferred();
        $browser = $this->getMockBuilder('Clue\React\Buzz\Browser')->disableOriginalConstructor()->getMock();
        $browser->expects($this->once())->method('withOptions')->willReturnSelf();
        $browser->expects($this->once())->method('get')->willReturn($deferred->promise());

        $es = new EventSource('http://example.com', $loop, $browser);

        $this->assertNotNull($timer);
        $timer();

        $caught = null;
        $es->on('error', function ($e) use (&$caught) {
            $caught = $e;
        });
        $deferred->reject($expected = new RuntimeException());

        $this->assertSame($expected, $caught);
    }

    public function testConstructorWillStartGetRequestThatWillNotStartRetryTimerWhenGetRequestRejectAndErrorHandlerClosesExplicitly()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $timer = null;
        $loop->expects($this->once())->method('addTimer')->with(0, $this->callback(function ($cb) use (&$timer) {
            $timer = $cb;
            return true;
        }));

        $deferred = new Deferred();
        $browser = $this->getMockBuilder('Clue\React\Buzz\Browser')->disableOriginalConstructor()->getMock();
        $browser->expects($this->once())->method('withOptions')->willReturnSelf();
        $browser->expects($this->once())->method('get')->with('http://example.com')->willReturn($deferred->promise());

        $es = new EventSource('http://example.com', $loop, $browser);

        $this->assertNotNull($timer);
        $timer();

        $es->on('error', function () use ($es) {
            $es->close();
        });
        $deferred->reject(new RuntimeException());
    }

    public function testCloseAfterGetRequestFromConstructorFailsWillCancelPendingRetryTimer()
    {
        $timer = $this->getMockBuilder('React\EventLoop\TimerInterface')->getMock();
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $timerStart = null;
        $loop->expects($this->exactly(2))->method('addTimer')->withConsecutive(
            [
                0,
                $this->callback(function ($cb) use (&$timerStart) {
                    $timerStart = $cb;
                    return true;
                })
            ],
            [
                3.0,
                $this->isType('callable')
            ]
        )->willReturnOnConsecutiveCalls(null, $timer);

        $deferred = new Deferred();
        $browser = $this->getMockBuilder('Clue\React\Buzz\Browser')->disableOriginalConstructor()->getMock();
        $browser->expects($this->once())->method('withOptions')->willReturnSelf();
        $browser->expects($this->once())->method('get')->with('http://example.com')->willReturn($deferred->promise());

        $es = new EventSource('http://example.com', $loop, $browser);

        $this->assertNotNull($timerStart);
        $timerStart();

        $deferred->reject(new RuntimeException());

        $es->close();
    }

    public function testConstructorWillReportFatalErrorWhenGetResponseResolvesWithInvalidStatusCodeAfterTimer()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $timer = null;
        $loop->expects($this->once())->method('addTimer')->with(0, $this->callback(function ($cb) use (&$timer) {
            $timer = $cb;
            return true;
        }));

        $response = new Response(400, array('Content-Type' => 'text/event-stream'), '');
        $browser = $this->getMockBuilder('Clue\React\Buzz\Browser')->disableOriginalConstructor()->getMock();
        $browser->expects($this->once())->method('withOptions')->willReturnSelf();
        $browser->expects($this->once())->method('get')->with('http://example.com')->willReturn(\React\Promise\resolve($response));

        $es = new EventSource('http://example.com', $loop, $browser);

        $readyState = null;
        $caught = null;
        $es->on('error', function ($e) use ($es, &$readyState, &$caught) {
            $readyState = $es->readyState;
            $caught = $e;
        });

        $this->assertNotNull($timer);
        $timer();

        $this->assertEquals(EventSource::CLOSED, $readyState);
        $this->assertInstanceOf('UnexpectedValueException', $caught);
    }

    public function testConstructorWillReportFatalErrorWhenGetResponseResolvesWithInvalidContentTypeAfterTimer()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $timer = null;
        $loop->expects($this->once())->method('addTimer')->with(0, $this->callback(function ($cb) use (&$timer) {
            $timer = $cb;
            return true;
        }));

        $response = new Response(200, array(), '');
        $browser = $this->getMockBuilder('Clue\React\Buzz\Browser')->disableOriginalConstructor()->getMock();
        $browser->expects($this->once())->method('withOptions')->willReturnSelf();
        $browser->expects($this->once())->method('get')->with('http://example.com')->willReturn(\React\Promise\resolve($response));

        $es = new EventSource('http://example.com', $loop, $browser);

        $readyState = null;
        $caught = null;
        $es->on('error', function ($e) use ($es, &$readyState, &$caught) {
            $readyState = $es->readyState;
            $caught = $e;
        });

        $this->assertNotNull($timer);
        $timer();

        $this->assertEquals(EventSource::CLOSED, $readyState);
        $this->assertInstanceOf('UnexpectedValueException', $caught);
    }

    public function testConstructorWillReportOpenWhenGetResponseResolvesWithValidResponseAfterTimer()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $timer = null;
        $loop->expects($this->once())->method('addTimer')->with(0, $this->callback(function ($cb) use (&$timer) {
            $timer = $cb;
            return true;
        }));

        $stream = new ThroughStream();
        $response = new Response(200, array('Content-Type' => 'text/event-stream'), new ReadableBodyStream($stream));
        $browser = $this->getMockBuilder('Clue\React\Buzz\Browser')->disableOriginalConstructor()->getMock();
        $browser->expects($this->once())->method('withOptions')->willReturnSelf();
        $browser->expects($this->once())->method('get')->with('http://example.com')->willReturn(\React\Promise\resolve($response));

        $es = new EventSource('http://example.com', $loop, $browser);

        $readyState = null;
        $es->on('open', function () use ($es, &$readyState) {
            $readyState = $es->readyState;
        });

        $this->assertNotNull($timer);
        $timer();

        $this->assertEquals(EventSource::OPEN, $readyState);
    }

    public function testConstructorWillReportOpenWhenGetResponseResolvesWithValidResponseWithCaseInsensitiveContentTypeAfterTimer()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $timer = null;
        $loop->expects($this->once())->method('addTimer')->with(0, $this->callback(function ($cb) use (&$timer) {
            $timer = $cb;
            return true;
        }));

        $stream = new ThroughStream();
        $response = new Response(200, array('CONTENT-type' => 'TEXT/Event-Stream'), new ReadableBodyStream($stream));
        $browser = $this->getMockBuilder('Clue\React\Buzz\Browser')->disableOriginalConstructor()->getMock();
        $browser->expects($this->once())->method('withOptions')->willReturnSelf();
        $browser->expects($this->once())->method('get')->with('http://example.com')->willReturn(\React\Promise\resolve($response));

        $es = new EventSource('http://example.com', $loop, $browser);

        $readyState = null;
        $es->on('open', function () use ($es, &$readyState) {
            $readyState = $es->readyState;
        });

        $this->assertNotNull($timer);
        $timer();

        $this->assertEquals(EventSource::OPEN, $readyState);
    }

    public function testConstructorWillReportOpenWhenGetResponseResolvesWithValidResponseAndSuperfluousParametersAfterTimer()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $timer = null;
        $loop->expects($this->once())->method('addTimer')->with(0, $this->callback(function ($cb) use (&$timer) {
            $timer = $cb;
            return true;
        }));

        $stream = new ThroughStream();
        $response = new Response(200, array('Content-Type' => 'text/event-stream;charset=utf-8;foo=bar'), new ReadableBodyStream($stream));
        $browser = $this->getMockBuilder('Clue\React\Buzz\Browser')->disableOriginalConstructor()->getMock();
        $browser->expects($this->once())->method('withOptions')->willReturnSelf();
        $browser->expects($this->once())->method('get')->with('http://example.com')->willReturn(\React\Promise\resolve($response));

        $es = new EventSource('http://example.com', $loop, $browser);

        $readyState = null;
        $es->on('open', function () use ($es, &$readyState) {
            $readyState = $es->readyState;
        });

        $this->assertNotNull($timer);
        $timer();

        $this->assertEquals(EventSource::OPEN, $readyState);
    }

    public function testCloseResponseStreamWillReturnToStartTimerToReconnectWithoutErrorEvent()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $timer = null;
        $loop->expects($this->exactly(2))->method('addTimer')->withConsecutive(
            [
                0,
                $this->callback(function ($cb) use (&$timer) {
                    $timer = $cb;
                    return true;
                })
            ],
            [
                3.0,
                $this->isType('callable')
            ]
        );

        $stream = new ThroughStream();
        $response = new Response(200, array('Content-Type' => 'text/event-stream'), new ReadableBodyStream($stream));
        $browser = $this->getMockBuilder('Clue\React\Buzz\Browser')->disableOriginalConstructor()->getMock();
        $browser->expects($this->once())->method('withOptions')->willReturnSelf();
        $browser->expects($this->once())->method('get')->with('http://example.com')->willReturn(\React\Promise\resolve($response));

        $es = new EventSource('http://example.com', $loop, $browser);

        $this->assertNotNull($timer);
        $timer();

        $error = null;
        $es->on('error', function ($e) use (&$error) {
            $error = $e;
        });

        $stream->close();

        $this->assertEquals(EventSource::CONNECTING, $es->readyState);
        $this->assertNull($error);
    }

    public function testCloseFromOpenEventWillCloseResponseStreamAndCloseEventSource()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $timer = null;
        $loop->expects($this->once())->method('addTimer')->with(0, $this->callback(function ($cb) use (&$timer) {
            $timer = $cb;
            return true;
        }));

        $stream = new ThroughStream();
        $response = new Response(200, array('Content-Type' => 'text/event-stream'), new ReadableBodyStream($stream));
        $browser = $this->getMockBuilder('Clue\React\Buzz\Browser')->disableOriginalConstructor()->getMock();
        $browser->expects($this->once())->method('withOptions')->willReturnSelf();
        $browser->expects($this->once())->method('get')->with('http://example.com')->willReturn(\React\Promise\resolve($response));

        $es = new EventSource('http://example.com', $loop, $browser);

        $es->on('open', function () use ($es) {
            $es->close();
        });

        $this->assertNotNull($timer);
        $timer();

        $this->assertEquals(EventSource::CLOSED, $es->readyState);
        $this->assertFalse($stream->isReadable());
    }

    public function testEmitMessageWithParsedDataFromEventStream()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $timer = null;
        $loop->expects($this->once())->method('addTimer')->with(0, $this->callback(function ($cb) use (&$timer) {
            $timer = $cb;
            return true;
        }));

        $stream = new ThroughStream();
        $response = new Response(200, array('Content-Type' => 'text/event-stream'), new ReadableBodyStream($stream));
        $browser = $this->getMockBuilder('Clue\React\Buzz\Browser')->disableOriginalConstructor()->getMock();
        $browser->expects($this->once())->method('withOptions')->willReturnSelf();
        $browser->expects($this->once())->method('get')->with('http://example.com')->willReturn(\React\Promise\resolve($response));

        $es = new EventSource('http://example.com', $loop, $browser);

        $this->assertNotNull($timer);
        $timer();

        $message = null;
        $es->on('message', function ($m) use (&$message) {
            $message = $m;
        });

        $stream->write("data: hello\n\n");

        $this->assertInstanceOf('Clue\React\EventSource\MessageEvent', $message);
        $this->assertEquals('hello', $message->data);
        $this->assertEquals('', $message->lastEventId);
    }

    public function testEmitMessageWithParsedIdAndDataOverMultipleRowsFromEventStream()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $timer = null;
        $loop->expects($this->once())->method('addTimer')->with(0, $this->callback(function ($cb) use (&$timer) {
            $timer = $cb;
            return true;
        }));

        $stream = new ThroughStream();
        $response = new Response(200, array('Content-Type' => 'text/event-stream'), new ReadableBodyStream($stream));
        $browser = $this->getMockBuilder('Clue\React\Buzz\Browser')->disableOriginalConstructor()->getMock();
        $browser->expects($this->once())->method('withOptions')->willReturnSelf();
        $browser->expects($this->once())->method('get')->with('http://example.com')->willReturn(\React\Promise\resolve($response));

        $es = new EventSource('http://example.com', $loop, $browser);

        $this->assertNotNull($timer);
        $timer();

        $message = null;
        $es->on('message', function ($m) use (&$message) {
            $message = $m;
        });

        $stream->write("id: 100\ndata: hello\ndata: world\n\n");

        $this->assertInstanceOf('Clue\React\EventSource\MessageEvent', $message);
        $this->assertEquals('hello' . "\n" . 'world', $message->data);
        $this->assertEquals('100', $message->lastEventId);
    }

    public function testEmitMessageWithParsedEventTypeAndDataWithTrailingWhitespaceFromEventStream()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $timer = null;
        $loop->expects($this->once())->method('addTimer')->with(0, $this->callback(function ($cb) use (&$timer) {
            $timer = $cb;
            return true;
        }));

        $stream = new ThroughStream();
        $response = new Response(200, array('Content-Type' => 'text/event-stream'), new ReadableBodyStream($stream));
        $browser = $this->getMockBuilder('Clue\React\Buzz\Browser')->disableOriginalConstructor()->getMock();
        $browser->expects($this->once())->method('withOptions')->willReturnSelf();
        $browser->expects($this->once())->method('get')->with('http://example.com')->willReturn(\React\Promise\resolve($response));

        $es = new EventSource('http://example.com', $loop, $browser);

        $this->assertNotNull($timer);
        $timer();

        $message = null;
        $es->on('patch', function ($m) use (&$message) {
            $message = $m;
        });

        $stream->write("event:patch\ndata:[]\ndata:\n\n");

        $this->assertInstanceOf('Clue\React\EventSource\MessageEvent', $message);
        $this->assertEquals('[]' . "\n", $message->data);
    }

    public function testDoesNotEmitMessageWhenParsedEventStreamHasNoData()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $timer = null;
        $loop->expects($this->once())->method('addTimer')->with(0, $this->callback(function ($cb) use (&$timer) {
            $timer = $cb;
            return true;
        }));

        $stream = new ThroughStream();
        $response = new Response(200, array('Content-Type' => 'text/event-stream'), new ReadableBodyStream($stream));
        $browser = $this->getMockBuilder('Clue\React\Buzz\Browser')->disableOriginalConstructor()->getMock();
        $browser->expects($this->once())->method('withOptions')->willReturnSelf();
        $browser->expects($this->once())->method('get')->with('http://example.com')->willReturn(\React\Promise\resolve($response));

        $es = new EventSource('http://example.com', $loop, $browser);

        $this->assertNotNull($timer);
        $timer();

        $message = null;
        $es->on('message', function ($m) use (&$message) {
            $message = $m;
        });

        $stream->write("id:123\n\n");

        $this->assertNull($message);
    }

    public function testEmitMessageWithParsedDataAndPreviousIdWhenNotGivenAgainFromEventStream()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $timer = null;
        $loop->expects($this->once())->method('addTimer')->with(0, $this->callback(function ($cb) use (&$timer) {
            $timer = $cb;
            return true;
        }));

        $stream = new ThroughStream();
        $response = new Response(200, array('Content-Type' => 'text/event-stream'), new ReadableBodyStream($stream));
        $browser = $this->getMockBuilder('Clue\React\Buzz\Browser')->disableOriginalConstructor()->getMock();
        $browser->expects($this->once())->method('withOptions')->willReturnSelf();
        $browser->expects($this->once())->method('get')->with('http://example.com')->willReturn(\React\Promise\resolve($response));

        $es = new EventSource('http://example.com', $loop, $browser);

        $this->assertNotNull($timer);
        $timer();

        $message = null;
        $es->on('message', function ($m) use (&$message) {
            $message = $m;
        });

        $stream->write("id:123\n\ndata:hi\n\n");

        $this->assertInstanceOf('Clue\React\EventSource\MessageEvent', $message);
        $this->assertEquals('hi', $message->data);
        $this->assertEquals('123', $message->lastEventId);
    }

    public function testReconnectAfterStreamClosesUsesLastEventIdFromParsedEventStreamForNextRequest()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $timerStart = $timerReconnect = null;
        $loop->expects($this->exactly(2))->method('addTimer')->withConsecutive(
            [
                0,
                $this->callback(function ($cb) use (&$timerStart) {
                    $timerStart = $cb;
                    return true;
                })
            ],
            [
                3.0,
                $this->callback(function ($cb) use (&$timerReconnect) {
                    $timerReconnect = $cb;
                    return true;
                })
            ]
        );

        $stream = new ThroughStream();
        $response = new Response(200, array('Content-Type' => 'text/event-stream'), new ReadableBodyStream($stream));
        $browser = $this->getMockBuilder('Clue\React\Buzz\Browser')->disableOriginalConstructor()->getMock();
        $browser->expects($this->once())->method('withOptions')->willReturnSelf();
        $browser->expects($this->exactly(2))->method('get')->withConsecutive(
            ['http://example.com', ['Accept' => 'text/event-stream', 'Cache-Control' => 'no-cache']],
            ['http://example.com', ['Accept' => 'text/event-stream', 'Cache-Control' => 'no-cache', 'Last-Event-ID' => '123']]
        )->willReturnOnConsecutiveCalls(
            \React\Promise\resolve($response),
            new Promise(function () { })
        );

        $es = new EventSource('http://example.com', $loop, $browser);

        $this->assertNotNull($timerStart);
        $timerStart();

        $stream->write("id:123\n\n");
        $stream->end();

        $this->assertNotNull($timerReconnect);
        $timerReconnect();
    }
}
