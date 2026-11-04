FROM node:22-alpine

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci --omit=dev

COPY src ./src
COPY scripts ./scripts

# The .proto contract is mounted from the project root (./proto -> /app/proto) by docker-compose
ENV PROTO_PATH=/app/proto/inventory/v1/inventory.proto \
    GRPC_PORT=50051

EXPOSE 50051

USER node
CMD ["node", "src/server.js"]
