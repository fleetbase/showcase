#!/usr/bin/env python3
"""The driver during a Passenger app capture.

The rider's half of a trip is the app on the simulator; this is the other half, played through
the Fleetbase API between Maestro flows, so the in-trip screens can be captured. It does what
Navigator does for a real driver: accept the offer, drive to the pickup, arrive, start the trip,
drive and complete it.

    passenger-driver.py <step> --url https://… [--fixtures .passenger-fixtures.json]

Steps, in order:
    accept    accept the rider's newest request as whichever seeded driver holds the offer,
              and put the car a few streets from the pickup
    arrive    the car at the pickup, status `arrived`
    start     the rider on board, status `in_progress`, the car part of the way
    complete  the car at the drop-off, status `completed`
    clear     delete the trip, so the next theme's run starts from the same history
    heartbeat send the car's position every 10 seconds until the trip is cleared, as Navigator
              does: the app warns that the car's position is out of date after a minute, and a
              Maestro flow can take that long to start

The trip it works on is kept in .passenger-ride.json between steps. Only the standard library,
so it runs on the macOS and Ubuntu runners as they are.
"""

import argparse
import json
import os
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

STATE = '.passenger-ride.json'


class Api:
    def __init__(self, url, key):
        self.base = url.rstrip('/') + '/v1/'
        self.key = key

    def call(self, method, path, body=None, query=None):
        url = self.base + path + ('?' + urllib.parse.urlencode(query) if query else '')
        data = json.dumps(body).encode() if body is not None else None
        request = urllib.request.Request(url, data=data, method=method, headers={
            'Authorization': f'Bearer {self.key}',
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            # Cloudflare's quick tunnels refuse Python's default user agent.
            'User-Agent': 'fleetbase-showcase-passenger-driver',
        })
        try:
            with urllib.request.urlopen(request, timeout=60) as response:
                text = response.read().decode() or 'null'
                return response.status, json.loads(text)
        except urllib.error.HTTPError as error:
            text = error.read().decode()
            try:
                return error.code, json.loads(text)
            except ValueError:
                return error.code, {'error': text[:300]}


def fail(message):
    print(f'::error::passenger driver: {message}', file=sys.stderr)
    sys.exit(1)


def point(place):
    coordinates = (place or {}).get('location', {}).get('coordinates')
    return (coordinates[1], coordinates[0]) if coordinates else None


def between(a, b, share):
    return (a[0] + (b[0] - a[0]) * share, a[1] + (b[1] - a[1]) * share)


def load_state():
    if not os.path.exists(STATE):
        fail(f'no trip in {STATE}; run accept first')
    return json.load(open(STATE))


def track(api, driver, at, remember=True):
    if remember and os.path.exists(STATE):
        state = json.load(open(STATE))
        state['car'] = at
        json.dump(state, open(STATE, 'w'))
    status, body = api.call('POST', f'drivers/{driver}/track', {'latitude': round(at[0], 6), 'longitude': round(at[1], 6), 'heading': 0, 'speed': 8})
    if status >= 400:
        print(f'::warning::passenger driver: tracking {driver} failed ({status}): {body}')


def advance(api, order, code):
    """Moves the order to the activity `code`, the way Navigator does: the next activity from the
    order's own flow, sent back as is."""
    status, options = api.call('GET', f'orders/{order}/next-activity')
    if status >= 400:
        fail(f'next activity for {order} ({status}): {options}')
    options = options if isinstance(options, list) else [options]
    activity = next((option for option in options if isinstance(option, dict) and option.get('code') == code), None)
    if not activity:
        fail(f'{code} is not a next step of {order}; it offers {[o.get("code") for o in options if isinstance(o, dict)]}')
    status, body = api.call('POST', f'orders/{order}/update-activity', {'activity': activity})
    if status >= 400:
        fail(f'{order} → {code} ({status}): {body}')
    print(f'==> {order}: {code}')


def find_request(api, deadline):
    """The rider's newest passenger order that no driver has taken yet."""
    while True:
        status, orders = api.call('GET', 'orders', query={'limit': 10, 'sort': '-created_at', 'type': 'passenger'})
        if status < 400 and isinstance(orders, list):
            for order in orders:
                if order.get('type') == 'passenger' and order.get('status') in ('created', 'dispatched') and not order.get('driver_assigned'):
                    return order
        if time.time() > deadline:
            fail(f'no open passenger request found ({status})')
        time.sleep(2)


def accept(api, fixtures, deadline):
    order = find_request(api, deadline)
    pickup, dropoff = point(order.get('payload', {}).get('pickup')), point(order.get('payload', {}).get('dropoff'))
    # Sequential dispatch offers to one driver at a time, so only that one may accept; the others
    # are refused with 409. Retried until the offer has been made (dispatch runs as the rider
    # requests, but the request may only just have landed).
    while True:
        for driver in fixtures['drivers']:
            status, body = api.call('POST', f'orders/{order["id"]}/start', {'assign': driver['id']})
            if status < 400:
                start = (driver['latitude'], driver['longitude'])
                # A few streets away, so the map shows the car approaching on the route.
                car = between(pickup, start, 0.45) if pickup else start
                json.dump({'order': order['id'], 'driver': driver['id'], 'pickup': pickup, 'dropoff': dropoff}, open(STATE, 'w'))
                track(api, driver['id'], car)
                print(f'==> {order["id"]}: accepted by {driver["id"]}')
                return
        if time.time() > deadline:
            fail(f'no seeded driver could accept {order["id"]}; last answer {status}: {body}')
        time.sleep(2)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('step', choices=['accept', 'arrive', 'start', 'complete', 'clear', 'heartbeat'])
    parser.add_argument('--url', required=True)
    parser.add_argument('--fixtures', default='.passenger-fixtures.json')
    parser.add_argument('--timeout', type=int, default=90)
    args = parser.parse_args()

    fixtures = json.load(open(args.fixtures))
    api = Api(args.url, fixtures['api_key'])
    deadline = time.time() + args.timeout

    if args.step == 'accept':
        return accept(api, fixtures, deadline)

    if args.step == 'heartbeat':
        while os.path.exists(STATE):
            state = json.load(open(STATE))
            if state.get('car'):
                track(api, state['driver'], state['car'], remember=False)
            time.sleep(10)
        return

    state = load_state()
    order, driver, pickup, dropoff = state['order'], state['driver'], state.get('pickup'), state.get('dropoff')
    if args.step == 'arrive':
        if pickup:
            track(api, driver, pickup)
        advance(api, order, 'arrived')
    elif args.step == 'start':
        advance(api, order, 'in_progress')
        if pickup and dropoff:
            track(api, driver, between(pickup, dropoff, 0.35))
    elif args.step == 'complete':
        if dropoff:
            track(api, driver, dropoff)
        advance(api, order, 'completed')
    elif args.step == 'clear':
        status, body = api.call('DELETE', f'orders/{order}')
        if status >= 400:
            print(f'::warning::passenger driver: deleting {order} failed ({status}): {body}')
        else:
            print(f'==> {order}: deleted')
        os.remove(STATE)


if __name__ == '__main__':
    main()
