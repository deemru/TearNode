<?php

require __DIR__ . '/../vendor/autoload.php';

use deemru\TearNode;
use deemru\WavesKit;
use React\EventLoop\Loop;

function react( $type )
{
    global $wk;
    $wk->log( $type );
    static $stats = 0;
    if( $type === 'T' )
        $stats |= 1;
    else
    if( $type === 'M' )
        $stats |= 2;
    else
    if( $type === 'B' )
        $stats |= 4;

    if( $stats === 7 )
    {
        $wk->log( 's', 'all types collected' );
        sleep( 3 );
        exit( 0 );
    }

    if( $type === 'E' )
    {
        $wk->log( 'e', 'timeout reached' );
        sleep( 3 );
        exit( 1 );
    }
}

$wk = new WavesKit( 'T' );
$tn = TearNode::TearNode( 'tcp://node-testnet.w8.io:6868' )->setWavesKit( $wk );
$tn->setOnTransaction( function(){ react( 'T' ); } );
$tn->setOnMicroblock( function(){ react( 'M' ); } );
$tn->setOnBlock( function(){ react( 'B' ); } );
$tn->setOnReport( function( $level, $message ){ global $wk; $wk->log( $level, $message ); } );
$tn->run();

// test timeout
Loop::get()->addTimer( 300, function(){ react( 'E' ); } );
