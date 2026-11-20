export default {
  async fetch(request) {
    const url = new URL(request.url);
    const target = 'https://api.elections.kalshi.com' + url.pathname.replace(/^\/proxy/, '') + url.search;

    const upstream = await fetch(target, {
      headers: {
        'Accept': 'application/json',
        'User-Agent': 'Mozilla/5.0',
      },
    });

    const body = await upstream.text();

    return new Response(body, {
      status: upstream.status,
      headers: {
        'Content-Type': 'application/json',
        'Access-Control-Allow-Origin': '*',
        'Cache-Control': 'public, max-age=30',
      },
    });
  },
};
