<?php

/**
 * The part of php-steamworks the engine uses. Only for static analysis - the
 * extension is optional and every call is guarded at runtime.
 *
 * @see \PHPolygon\Net\SteamTransport
 * @see \PHPolygon\Platform\SteamSession
 * @see \PHPolygon\Platform\SteamLanguage
 */

declare(strict_types=1);

function steam_net_init_relay_network_access(): bool {}

function steam_net_create_listen_socket_p2p(int $virtual_port = 0): int|false {}

function steam_net_connect_p2p(int $steam_id, int $virtual_port = 0): int|false {}

function steam_net_accept_connection(int $connection): int|false {}

function steam_net_close_connection(int $connection, int $reason = 0, ?string $debug = null, bool $linger = false): bool {}

function steam_net_send_message(int $connection, string $data, bool $reliable = true): int|false {}

/** @return list<array{connection: int, data: string}>|false */
function steam_net_receive_messages(int $connection, int $max = 32): array|false {}

/** @return list<array{connection: int, data: string}>|false */
function steam_net_receive_messages_on_poll_group(int $group, int $max = 32): array|false {}

/**
 * Connection state changes since the last call. listen_socket names the socket
 * an incoming connection arrived on, and is 0 for an outgoing one.
 *
 * @return list<array{connection: int, state: int, old_state: int, peer: int, listen_socket: int}>
 */
function steam_net_get_connection_events(): array {}

function steam_net_close_listen_socket(int $socket): bool {}

function steam_net_create_poll_group(): int|false {}

function steam_net_destroy_poll_group(int $group): bool {}

function steam_net_set_connection_poll_group(int $connection, int $group): bool {}

function steam_init(): bool {}

function steam_shutdown(): void {}

function steam_run_callbacks(): void {}

function steam_apps_get_language(): string|false {}

function steam_utils_get_steam_ui_language(): string|false {}
