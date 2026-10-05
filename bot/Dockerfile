# StreamOrg chat bot. Deployed as its own Coolify app from this folder
# (base directory /bot), next to the PHP app and sharing its database and
# APP_KEY. No public port is needed; /health is for the container check.
FROM node:24-alpine

ENV NODE_ENV=production
WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci --omit=dev && npm cache clean --force

COPY src/ src/

USER node
EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD wget -qO- http://127.0.0.1:8080/health > /dev/null || exit 1

CMD ["node", "src/index.js"]
