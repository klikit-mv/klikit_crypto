import { createServer } from './app.js';

const port = Number(process.env.PORT ?? 8081);
const host = process.env.HOST ?? '127.0.0.1';

createServer({ token: process.env.BRIDGE_TOKEN }).listen(port, host, () => {
    console.log(`execution bridge listening on ${host}:${port}`);
});
