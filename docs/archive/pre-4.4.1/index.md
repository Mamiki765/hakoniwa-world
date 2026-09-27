# 4.4.1開始時に分離した旧本文

Ownerの明示指示がある対象だけ閲覧する。通常の再開・検索には使わない。

起点commit：`d4bf02e5c46fc5fe69d25ea8c14e61a3474a0caa`。本文は元のblobをそのまま保持し、旧時点の誤記や相対リンクも含む。相対リンクは当時の元pathで解釈し、current authorityへ自動昇格しない。現行contractと未完事項はarchive外へ分離済み。

| 元path（このdirectory以下でも同じ配置） | 元blob SHA |
|---|---|
| `README.md` | `4113c9f1d06a1fb3e54e2842d2577a6f5620598e` |
| `docs/README.md` | `ca17486df07a30dc8c6395157a153ec7f96868de` |
| `docs/documentation-inventory.md` | `b945e415e256ddc729ce0befedf90589c9aeb758` |
| `docs/architecture/mvp-implementation.md` | `26950b59a97e92a63500613ca0a30bb4ef861f5f` |
| `product/docs/handoffs/current-status.md` | `3abacdc0a92a759bb92613c2fd2616b051b075ca` |
| `product/docs/handoffs/development-history-and-current-handoff.md` | `cff21ee8fb89ae8f36f807749f4f6abc88277c47` |
| `product/docs/handoffs/conversation-2026-09-21.md` | `2ff533a979ff87a37c40863a4dc659f7b4647939` |
| `product/docs/handoffs/conversation-2026-09-22.md` | `e48ff2b4d15d8a1421001a5093b710a124e28ec0` |
