<?php

namespace Proto\Socket\Transmission;

use Evenement\EventEmitter;
use Proto\Pack\Pack;
use Proto\Pack\PackInterface;
use Proto\Pack\Unpack;
use Proto\Session\Exception\SessionException;
use Proto\Session\SessionManagerInterface;
use Psr\Log\LoggerAwareTrait;
use React\Socket\ConnectionInterface;

class Handshake extends EventEmitter implements HandshakeInterface
{
    use LoggerAwareTrait;

    /**
     * @var ConnectionInterface
     */
    private $conn;

    /**
     * @var Unpack
     */
    private $unpack;

    /**
     * @var SessionManagerInterface
     */
    private $sessionManager;

    private $key;

    public function __construct(ConnectionInterface $conn, SessionManagerInterface $sessionManager)
    {
        $this->conn = $conn;
        $this->unpack = new Unpack();
        $this->sessionManager = $sessionManager;

        $this->conn->on('data', [$this->unpack, 'feed']);
        $this->unpack->on('unpack', [$this, 'unpack']);
    }

    public function handshake(string $key = null)
    {
        $this->key = $key;

        if ($this->key === null)
            $this->conn->write((new Pack())->setHeader([self::ACTION_REQUEST]));
        else
            $this->conn->write((new Pack())->setHeader([self::ACTION_REQUEST, $this->key]));
    }

    public function unpack(PackInterface $pack)
    {
        $action = $pack->getHeaderByKey(0);
        switch ($action) {

            // Server side
            case self::ACTION_REQUEST:
                $key = $pack->getHeaderByKey(1);

                // Recover Session
                if (isset($key)) {
                    try {
                        $session = $this->sessionManager->start($key);
                    } catch (SessionException $e) {
                        switch ($e->getCode()) {
                            case SessionException::ERR_INVALID_SESSION_KEY:
                                isset($this->logger) && $this->logger->critical("[Handshake]: Invalid session's key! key: '$key'");
                                $this->conn->write((new Pack)->setHeader([self::ACTION_ERROR]));
                                break;

                            default:
                                isset($this->logger) && $this->logger->critical("[Handshake]: Something wrong in recover session! key: '$key'");
                                $this->conn->write((new Pack)->setHeader([self::ACTION_ERROR]));
                        }
                        $this->emit('error');
                        return;
                    }

                    $this->conn->write((new Pack)->setHeader([self::ACTION_ESTABLISHED]));
                    $this->emit('established', [$session]);
                    return;
                }

                // New Session
                try {
                    $session = $this->sessionManager->start();
                } catch (SessionException $e) {
                    isset($this->logger) && $this->logger->critical("[Handshake]: Something wrong in generate new session!");
                    $this->conn->write((new Pack)->setHeader([self::ACTION_ERROR]));
                    $this->emit('error');
                    return;
                }

                $this->conn->write((new Pack)->setHeader([self::ACTION_ESTABLISHED]));
                $this->emit('established', [$session]);
                return;

            // Client side
            case self::ACTION_ESTABLISHED:
                try {
                    $session = isset($this->key) ? $this->sessionManager->start($this->key) : $this->sessionManager->start();
                } catch (SessionException $e) {
                    isset($this->logger) && $this->logger->critical("[Handshake]: Unable to recover session in client side!");
                    $this->emit('error');
                    return;
                }

                $this->emit('established', [$session]);
                return;

            case self::ACTION_ERROR:
                isset($this->logger) && $this->logger->critical("[Handshake] Handshake failed from remote host!");
                $this->emit('error');
                return;
        }
    }
}