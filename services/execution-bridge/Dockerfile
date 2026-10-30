FROM node:22-alpine
WORKDIR /srv
COPY package.json package-lock.json ./
RUN npm ci --omit=dev
COPY app.js server.js ./
USER node
EXPOSE 8081
CMD ["node", "server.js"]
