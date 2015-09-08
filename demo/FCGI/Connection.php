<?php
/**
 * @author Alexander.Lisachenko
 * @date 15.07.2014
 */

namespace Protocol\FCGI;

use Protocol\FCGI;
use Protocol\FCGI\Record\BeginRequest;
use Protocol\FCGI\Record\EndRequest;
use Protocol\FCGI\Record\Params;
use Protocol\FCGI\Record\Stdout;

class Connection
{
    protected $tempData = '';

    protected $connection = null;

    protected $base = null;

    protected $id = null;

    protected $buffer = null;

    /**
     * @var \Generator|null
     */
    protected $protocol = null;

    public function __construct($connection, $base, $id)
    {
        $this->connection = $connection;
        $this->base       = $base;
        $this->id         = $id;
        $this->protocol   = $this->handler();
        $this->protocol->rewind();
    }

    public function handle()
    {
        $this->buffer = $buffer = event_buffer_new(
            $this->connection,
            array($this, 'onRead'),
            array($this, 'onWrite'),
            array($this, 'onError')
        );
        event_buffer_base_set($buffer, $this->base);
        event_buffer_watermark_set($buffer, EV_READ, 0, 0xffffff);
        event_buffer_priority_set($buffer, 10);
        event_buffer_enable($buffer, EV_READ|EV_WRITE);
    }

    public function onError($buffer, $error)
    {
        $this->stop($buffer);

        $exception = new \RuntimeException($error);
        $this->protocol->throw($exception);
    }

    public function onWrite()
    {
        // If we have waiting messages, we should deliver them before
        while ($outputRecord = $this->protocol->current()) {
            $length  = strlen($outputRecord);
            $written = fwrite($this->connection, $outputRecord);
            if ($length !== $written) {
                throw new \LogicException("Write error. Part of message was not delivered");
            }
            $this->protocol->next();
        };

        if (!$this->protocol->valid()) {
            $this->stop();
        } elseif ($this->protocol->key() === 0) {
            // Strange situation, need to investigate, destructor of the connection won't be called too
            $this->stop();
        }

    }

    public function onRead()
    {
        while ($partialData = event_buffer_read($this->buffer, 4096)) {
            $this->tempData .= $partialData;
            while ($data = $this->tryReadFrameData()) {
                $record = $this->consumeRecord($data['type']);
                $this->protocol->send($record);
            };
        };

        if (!$this->protocol->valid()) {
            $this->stop();
        }
    }

    /**
     * Returns a protocol handler
     *
     * @return \Generator
     */
    public function handler()
    {
        /** @var BeginRequest $beginRequest */
        $beginRequest = yield;

        // Read stream of params, last message will be with empty content size
        $params = array();
        do {
            /** @var Params $paramsRequest */
            $paramsRequest = yield;
            $params += $paramsRequest->getValues();
        } while ($paramsRequest->getContentLength());

        // Read STDIN stream
        $stdin = '';
        do {
            /** @var Record $record */
            $record = yield;
            $stdin .= $record->getContentData();
        } while ($record->getContentLength());


        ob_start();
        $_SERVER = $params;
        parse_str($params['QUERY_STRING'], $_GET);
        if (strpos($_SERVER['CONTENT_TYPE'], 'application/x-www-form-urlencoded')===0) {
            parse_str($stdin, $_POST);
        }
        if (isset($params['HTTP_COOKIE'])) {
            parse_str($params['HTTP_COOKIE'], $_COOKIE);
        }

        $scriptName = $params['DOCUMENT_ROOT'] . $params['SCRIPT_FILENAME'];
        include $scriptName;
        $content = ob_get_clean();

        $length = strlen($content) + 1;
        $response = new Stdout(<<<CONTENT
Status: 200 OK
Content-Type: text/html
Content-Length: $length


$content
CONTENT
        );
        $response->setRequestId($beginRequest->getRequestId());
        yield $response;

        $response->setContentData('');
        yield $response;

        $endRequest = new EndRequest();
        $endRequest->setRequestId($beginRequest->getRequestId());
        yield $endRequest;
    }

    protected function tryReadFrameData()
    {
        $bufferLength = strlen($this->tempData);
        if ($bufferLength < FCGI::HEADER_LEN) {
            return false;
        }

        $fastInfo = unpack(FCGI::HEADER_FORMAT, $this->tempData);
        if ($bufferLength < FCGI::HEADER_LEN + $fastInfo['contentLength'] + $fastInfo['paddingLength']) {
            return false;
        }

        return $fastInfo;
    }

    /**
     * @param $recordType
     *
     * @return Record
     */
    protected function consumeRecord($recordType)
    {
        switch ($recordType) {
            case FCGI::BEGIN_REQUEST:
                $record = BeginRequest::unpack($this->tempData);
                break;

            case FCGI::PARAMS:
                $record = Params::unpack($this->tempData);
                break;

            default:
                $record = Record::unpack($this->tempData);
                break;
        }

        $offset = FCGI::HEADER_LEN + $record->getContentLength() + $record->getPaddingLength();
        $this->tempData = substr($this->tempData, $offset);

        return $record;
    }

    /**
     * Stops current buffered events and closes connection
     */
    protected function stop()
    {
        fclose($this->connection);
        event_buffer_disable($this->buffer, EV_READ|EV_WRITE);
        event_buffer_free($this->buffer);
    }
}
