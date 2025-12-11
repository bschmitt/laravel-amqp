<?php

/**
 * Example RPC Client Usage
 * 
 * This file demonstrates how to use the RPC client functionality
 * in your Laravel application.
 */

namespace App\Examples;

use Bschmitt\Amqp\Facades\Amqp;
use Illuminate\Http\Request;

class RpcClientExample
{
    /**
     * Example: Make an RPC call from a controller
     */
    public function makeRpcCall(Request $request)
    {
        $requestData = $request->input('data');
        
        // Make RPC call with 30 second timeout
        $response = Amqp::rpc('service-queue', $requestData, [
            'exchange' => 'amq.direct',
            'exchange_type' => 'direct',
        ], 30);
        
        if ($response !== null) {
            return response()->json([
                'status' => 'success',
                'data' => $response,
            ]);
        } else {
            return response()->json([
                'status' => 'error',
                'message' => 'Request timed out or no response received',
            ], 504);
        }
    }

    /**
     * Example: RPC call with error handling
     */
    public function makeRpcCallWithErrorHandling($data)
    {
        try {
            $response = Amqp::rpc('service-queue', json_encode($data), [
                'exchange' => 'amq.direct',
                'exchange_type' => 'direct',
            ], 30);
            
            if ($response === null) {
                throw new \RuntimeException('RPC call timed out');
            }
            
            return json_decode($response, true);
            
        } catch (\Exception $e) {
            \Log::error('RPC call failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Example: Multiple RPC calls
     */
    public function makeMultipleRpcCalls(array $requests)
    {
        $responses = [];
        
        foreach ($requests as $key => $requestData) {
            $response = Amqp::rpc('service-queue', $requestData, [
                'exchange' => 'amq.direct',
                'exchange_type' => 'direct',
            ], 10); // Shorter timeout for batch operations
            
            $responses[$key] = $response;
        }
        
        return $responses;
    }

    /**
     * Example: RPC call with custom properties
     */
    public function makeRpcCallWithProperties($data)
    {
        $response = Amqp::rpc('service-queue', $data, [
            'exchange' => 'amq.direct',
            'exchange_type' => 'direct',
            'priority' => 10,  // High priority
            'application_headers' => [
                'x-user-id' => auth()->id(),
                'x-request-id' => uniqid(),
            ],
        ], 30);
        
        return $response;
    }
}

