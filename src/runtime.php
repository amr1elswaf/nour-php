<?php

/**
 * ═══════════════════════════════════════════════════════════════
 *  API Reference — Gooabb / Nour Platform
 * ═══════════════════════════════════════════════════════════════
 *
 * ┌─────────────────────────────────────────────────────────────┐
 * │  REQUEST FORMAT                                             │
 * └─────────────────────────────────────────────────────────────┘
 *
 *  Method  : POST
 *  URL     : https://pma.gooabb.com/nourapi/v1/
 *  Headers : Content-Type: application/x-www-form-urlencoded
 *
 *  Body fields:
 *  ┌───────────┬────────────────────────────────────────────────┐
 *  │  Field    │  Description                                   │
 *  ├───────────┼────────────────────────────────────────────────┤
 *  │  API      │  50-char session key (from login response)     │
 *  │  req      │  Operation name e.g. "GET USER PROFILE"        │
 *  │  data     │  JSON string with operation payload            │
 *  └───────────┴────────────────────────────────────────────────┘
 *
 *  Example:
 *    API  = 58be13fb213d145d68acbe33bd8f8ea0a92fbdfd5828a91f7c
 *    req  = INSERT MY PROFILE
 *    data = {"role":"Teacher","name":"Ahmed","gender":"M"}
 *
 *
 * ┌─────────────────────────────────────────────────────────────┐
 * │  RESPONSE TYPES  (all responses are JSON)                   │
 * └─────────────────────────────────────────────────────────────┘
 *
 *  ── Success ──────────────────────────────────────────────────
 *  HTTP 200
 *  { "success": "success", "code": "success" }
 *
 *  ── Data ─────────────────────────────────────────────────────
 *  HTTP 200
 *  { ...any object or array... }
 *
 *  ── Message ──────────────────────────────────────────────────
 *  HTTP 200
 *  { "<key>": "<message>", "code": "<key>" }
 *
 *  ── Fail ─────────────────────────────────────────────────────
 *  HTTP 400
 *  { "failed": "<reason>", "code": "failed" }
 *
 *  ── Error response ───────────────────────────────────────────
 *  HTTP 4xx / 5xx
 *  { "error": "<message>", "part": "<part>", "code": "<code>" }
 *
 *  Error codes:
 *  ┌─────────────────┬──────────────┬───────────────────────────┐
 *  │  code           │  part        │  HTTP                     │
 *  ├─────────────────┼──────────────┼───────────────────────────┤
 *  │  bad_request    │  validation  │  400                      │
 *  │  unauthorized   │  auth        │  401                      │
 *  │  forbidden      │  auth        │  403                      │
 *  │  not_found      │  router      │  404                      │
 *  │  server_error   │  system      │  500                      │
 *  │  error          │  unknown     │  400 (default)            │
 *  └─────────────────┴──────────────┴───────────────────────────┘
 *
 *
 * ┌─────────────────────────────────────────────────────────────┐
 * │  WEBSOCKET                                                  │
 * └─────────────────────────────────────────────────────────────┘
 *
 *  Incoming messages follow the same req/data pattern over WS.
 *  Outgoing messages:
 *  { "type": "<event>", "data": { ...payload... } }
 *
 *  Error over WS:
 *  { "type": "error", "data": { "error": "<message>" } }
 *
 * ═══════════════════════════════════════════════════════════════
 */

use  Nour\core\http\ApiResponse;
use Swoole\Coroutine;

/*
function error(string $msg, string $part = 'unknow', $name = 'error')
{
    throw new Exception(json_encode(['error' => $msg,'part'=>$part, 'code' => $name], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}
// دوال المخرجات
function msg(string $msg, string $key = 'message'): never
{
    throw new Exception(([$key => $msg, 'code' => $key], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function echo_data(array|object $data): never
{
    throw new Exception(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function sucss(string $msg = 'success'): never
{
    throw new Exception(json_encode(['success' => $msg,  'code' => 'success'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function fail(string $cause = 'failed')
{

    throw new Exception(json_encode(['failed' => $cause, 'code' => 'failed'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}
*/


function error(string $msg, int $code = 400, string $part = 'unknow', string $name = 'error'): void
{
    $ctx = Coroutine::getContext();
    if (!isset($ctx['type'])) {
        throw new ErrorException("unfinded type");
    }

    if ($ctx['type'] === 'h') {
        if (!isset($ctx['response'])) {
            throw new ErrorException("unfinded response");
        }

        $ctx['response']->status($code);
        $ctx['response']->header('Content-Type', 'application/json; charset=utf-8');
        $ctx['response']->end(json_encode(['error' => $msg, 'part' => $part, 'code' => $name], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        throw new ApiResponse();
    } else {
        socket_error($msg);
    }
}


function end_with_txt(string  $msg): void
{
    $ctx = Coroutine::getContext();
    if (!isset($ctx['response'])) {
        throw new ErrorException("unfinded response");
    }
    $ctx['response']->status(200);
    $ctx['response']->header('Content-Type', 'text/plain; charset=utf-8');
    $ctx['response']->end($msg);
    throw new ApiResponse();
}
// دوال المخرجات
function msg(string $msg, string $key = 'message'): void
{
    $ctx = Coroutine::getContext();
    if (!isset($ctx['response'])) {
        throw new ErrorException("unfinded response");
    }
    $ctx['response']->status(200);
    $ctx['response']->header('Content-Type', 'application/json; charset=utf-8');
    $ctx['response']->end(json_encode([$key => $msg, 'code' => $key], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    throw new ApiResponse();
}

function echo_data(array|object $data): void
{
    $ctx = Coroutine::getContext();
    if (!isset($ctx['response'])) {
        throw new ErrorException("unfinded response");
    }
    $ctx['response']->status(200);
    $ctx['response']->header('Content-Type', 'application/json; charset=utf-8');
    $ctx['response']->end(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    throw new ApiResponse();
}

function sucss(string $msg = 'success', array $added = []): void
{
    $ctx = Coroutine::getContext();
    if (!isset($ctx['response'])) {
        throw new ErrorException("unfinded response");
    }
    $ctx['response']->status(200);
    $ctx['response']->header('Content-Type', 'application/json; charset=utf-8');
    if (!empty($added)) {
        $added['success'] = $msg;
        $added['code'] = 'success';
    } else {
        $added = ['success' => $msg,  'code' => 'success'];
    }


    $ctx['response']->end(

        json_encode(
            $added,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        )
    );
    throw new ApiResponse();
}

function fail(string $cause = 'failed')
{
    $ctx = Coroutine::getContext();
    if (!isset($ctx['response'])) {
        throw new ErrorException("unfinded response");
    }
    $ctx['response']->status(400);
    $ctx['response']->header('Content-Type', 'application/json; charset=utf-8');
    $ctx['response']->end(json_encode(['failed' => $cause, 'code' => 'failed'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    throw new ApiResponse();
}
/**
 * end request
 * @throws ApiResponse
 * @return never
 */
function end_request()
{
    throw new ApiResponse();
}
/**
 * دالة لاؤسال رسالة لسوكيت و انهاء الطلب
 * @param string $type
 * @param array $data
 * @throws ErrorException
 * @return void
 */
function send_end(string $type = 'message', array $message = [])
{
    send($type, $message);
    end_request();
}

/**
 * ارسال رسالة فقط
 * @param string $type
 * @param array $data
 * @throws ErrorException
 * @return never
 */
function send(string $type = 'message', array $message = []): void
{
    $ctx = Coroutine::getContext();
    if (!isset($ctx['socket_id']) || !isset($ctx['manger'])) {
        throw new ErrorException("unfinded socket_id in Coroutine");
    }
    $manger = $ctx['manger'];
    $manger->sendMessage($ctx['socket_id'], ['type' => $type, 'data' => $message]);
    return;
}



function socket_error(string $msg): void
{
    send_end('error', ['error' => $msg]);
}
