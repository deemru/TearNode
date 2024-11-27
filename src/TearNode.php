<?php

namespace deemru;

use deemru\WavesKit;
use React\Socket\Connector;
use React\EventLoop\Loop;

class TearNode
{
    private $wk;
    private $tcpNode;
    private $network;
    private $loop;
    private $onBlock;
    private $onMicroblock;
    private $onTransaction;
    private $onReport;

    private $magic;
    private $stream;
    private $data;
    private $first;
    private $connecting;
    private $appName;
    private $version;
    private $nodeName;
    private $address;
    private $port;
    private $nonce;
    private $connector;
    private $retryDelay = 16.0;

    private function __construct(){}
    static public function TearNode( $tcpNode = null, $network = 'W' )
    {
        $tn = new TearNode;
        $tn->tcpNode = isset( $tcpNode ) ? $tcpNode : ( 'tcp://' . $tn->defaultNodeAddress() . ':6868' );
        $tn->network = $network;
        return $tn->setParams();
    }

    private function defaultNodeAddress()
    {
        return '127.0.0.1';
    }

    private function defaultWavesKit()
    {
        $wk = new WavesKit( $this->network );
        $wk->setNodeAddress( 'http://' . $this->defaultNodeAddress() . ':6869' );
        return $wk;
    }

    private function defaultClosure()
    {
        return function(){};
    }

    private function defaultReport()
    {
        return function( $level, $message ){ $this->wk->log( $level, $message ); };
    }

    public function setParams( $wk = null, $loop = null, $onBlock = false, $onMicroblock = false, $onTransaction = false, $onReport = false ) : TearNode
    {
        $this->wk = isset( $wk ) ? $wk : $this->defaultWavesKit();
        $this->loop = isset( $loop ) ? $loop : Loop::get();
        $this->onBlock = $onBlock === false ? $this->defaultClosure() : $onBlock;
        $this->onMicroblock = $onMicroblock === false ? $this->defaultClosure() : $onMicroblock;
        $this->onTransaction = $onTransaction === false ? $this->defaultClosure() : $onTransaction;
        $this->onReport = $onReport === false ? $this->defaultReport() : $onReport;
        $this->magic = hex2bin( '12345678' );
        $this->stream = false;
        $this->connector = new Connector( [], $this->loop );
        $this->connecting = false;
        return $this;
    }

    public function setWavesKit( $wk )
    {
        $this->wk = $wk;
        return $this;
    }

    public function setLoop( $loop )
    {
        $this->loop = $loop;
        return $this;
    }

    public function setOnBlock( $onBlock )
    {
        $this->onBlock = $onBlock;
        return $this;
    }

    public function setOnMicroblock( $onMicroblock )
    {
        $this->onMicroblock = $onMicroblock;
        return $this;
    }

    public function setOnTransaction( $onTransaction )
    {
        $this->onTransaction = $onTransaction;
        return $this;
    }

    public function setOnReport( $onReport )
    {
        $this->onReport = $onReport;
        return $this;
    }

    public function setRetryDelay( $retryDelay )
    {
        $this->retryDelay = $retryDelay;
        return $this;
    }

    private function newStream()
    {
        if( $this->stream !== false || $this->connecting === true )
            return;

        $this->connecting = true;
        $this->connector->connect( $this->tcpNode )->then( function( $stream )
        {
            ($this->onReport)( 's', 'TearNode: connect( ' . $this->tcpNode . ' )' );

            $stream->on( 'data', function( $data ){ $this->onData( $data ); } );
            $stream->on( 'end', function(){ $this->onClose(); } );
            $stream->on( 'close', function(){ $this->onClose(); } );
            $stream->on( 'error', function( \Exception $e ){ $this->onClose( $e ); } );

            $this->data = '';
            $this->first = true;
            $this->stream = $stream;
            $this->connecting = false;
            $this->stream->write( $this->myHandshake() );
        },
        function( \Exception $e )
        {
            ($this->onReport)( 'e', 'TearNode: connect(): ' . $e->getMessage() );
            $this->retry();
        } );
    }

    public function run()
    {
        $this->newStream();
        return $this;
    }

    public function injectTransaction( $data )
    {
        if( $this->stream === false )
            return false;

        $data = pack( 'N', strlen( $data ) ) . substr( $this->wk->blake2b256( $data ), 0, 4 ) . $data;
        $data = pack( 'N', 5 + strlen( $data ) ) . $this->magic . chr( 31 ) . $data;
        return $this->stream->write( $data );
    }

    public function injectRaw( $data )
    {
        if( $this->stream === false )
            return false;

        return $this->stream->write( $data );
    }

    private function myHandshake()
    {
        $this->appName = 'waves' . $this->network;
        $this->version = '1.5.8';
        $this->nodeName = 'WavesTearNode';
        $this->nonce = random_bytes( 8 );
        $this->address = '127.0.0.1';
        $this->port = '6868';

        $out = '';
        $out .= chr( strlen( $this->appName ) ) . $this->appName;
        $out .= pack( 'N', explode( '.', $this->version )[0] ) . pack( 'N', explode( '.', $this->version )[1] ) . pack( 'N', explode( '.', $this->version )[2] );
        $out .= chr( strlen( $this->nodeName ) ) . $this->nodeName;
        $out .= $this->nonce;
        $out .= pack( 'N', 8 );
        $out .= chr( explode( '.', $this->address )[0] ) . chr( explode( '.', $this->address )[1] ) . chr( explode( '.', $this->address )[2] ) . chr( explode( '.', $this->address )[3] );
        $out .= pack( 'N', $this->port );
        $out .= pack( 'J', $this->wk->timestamp( true ) );
        return $out;
    }

    private function read_n( &$data, $n )
    {
        if( strlen( $data ) < $n )
            return false;

        $var = substr( $data, 0, $n );
        $data = substr( $data, $n );
        return $var;
    }

    private function onData( $datain )
    {
        if( $this->stream === false )
            return;

        if( $this->first === true )
        {
            $this->first = false;
            return;
        }

        $datain = $this->data . $datain;

        {
            $data = $datain;

            $onBlockOnce = false;
            $onMicroblockOnce = false;
            $onTransactionOnce = false;

            for( ;; )
            {
                $var = $this->read_n( $data, 4 );
                if( $var === false )
                    break;

                $hdrlen = unpack( 'N', $var )[1];

                if( $hdrlen > 0 )
                {
                    $var = $this->read_n( $data, 4 );
                    if( $var === false )
                        break;

                    if( $var !== $this->magic )
                    {
                        $this->wk->log( 'w', 'BAD MAGIC' );
                        $this->onClose();
                        return;
                    }

                    $var = $this->read_n( $data, 1 );
                    if( $var === false )
                        break;

                    $id = ord( $var );

                    if( $hdrlen >= 13 )
                    {
                        $var = $this->read_n( $data, 4 );
                        if( $var === false )
                            break;

                        $datalen = unpack( 'N', $var )[1];

                        if( $datalen > 0 )
                        {
                            $var = $this->read_n( $data, 4 );
                            if( $var === false )
                                break;

                            $payload = $this->read_n( $data, $datalen );
                            if( $payload === false )
                                break;
                        }
                    }

                    // PING
                    if( $id === 1 )
                    {
                        static $pong;
                        if( !isset( $pong ) )
                            $pong = hex2bin( '0000003912345678020000002c26af4d4c000000059f457e9900001acf5e82acc900001acf9f457e9500001acf5e8269ef00001acf7f00000100001ad4' );
                        $this->stream->write( $pong );
                    }
                    else
                    // BLOCK + MICROBLOCK
                    if( $id === 23 || $id === 26 || $id === 29 || $id === 30 )
                    {
                        if( $id === 29 )
                        {
                            if( $onBlockOnce === false )
                            {
                                $onBlockOnce = true;
                                ($this->onBlock)();
                            }
                        }
                        if( $onMicroblockOnce === false )
                        {
                            $onMicroblockOnce = true;
                            ($this->onMicroblock)();
                        }
                    }
                    else
                    // TRANSACTION
                    if( $id === 25 || $id === 31 )
                    {
                        if( $onTransactionOnce === false )
                        {
                            $onTransactionOnce = true;
                            ($this->onTransaction)();
                        }
                    }
                }

                $datain = $data;
            }
        }

        $this->data = $datain;
    }

    private function retry()
    {
        ($this->onReport)( 'i', 'TearNode: retry(): delay for ' . $this->retryDelay . ' seconds...' );
        $this->loop->addTimer( $this->retryDelay, function(){ $this->newStream(); } );
    }

    private function onClose( $e = null )
    {
        if( $this->stream !== false )
        {
            $this->stream->close();
            $this->stream = false;
            ($this->onReport)( 'e', 'TearNode: onClose(): ' . ( isset( $e ) ? $e->getMessage() : 'unknown reason' ) );
            $this->retry();
        }
    }
}
