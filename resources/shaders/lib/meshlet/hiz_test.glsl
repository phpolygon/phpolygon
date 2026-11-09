            if (visible && u_ms_hiz.w > 0.5) {
                vec2 lo = vec2(1e9);
                vec2 hi = vec2(-1e9);
                float nearest = 1e9;
                bool crossesNear = false;
                for (int k = 0; k < 8; k++) {
                    vec3 p = c.xyz + r * vec3((k & 1) != 0 ? 1.0 : -1.0, (k & 2) != 0 ? 1.0 : -1.0, (k & 4) != 0 ? 1.0 : -1.0);
                    vec4 q = vp * vec4(p, 1.0);
                    if (q.w <= 1e-4) {
                        crossesNear = true;
                    } else {
                        vec3 n = q.xyz / q.w;
                        lo = min(lo, n.xy);
                        hi = max(hi, n.xy);
                        nearest = min(nearest, n.z);
                    }
                }
                if (!crossesNear && nearest > -1.0) {
                    vec2 uv0 = clamp(vec2(lo.x * 0.5 + 0.5, 0.5 - hi.y * 0.5), 0.0, 1.0);
                    vec2 uv1 = clamp(vec2(hi.x * 0.5 + 0.5, 0.5 - lo.y * 0.5), 0.0, 1.0);
                    vec2 span = (uv1 - uv0) * u_ms_hiz.xy;
                    int lod = int(clamp(ceil(log2(max(max(span.x, span.y), 1.0))), 0.0, u_ms_hiz.z));
                    ivec2 dim = textureSize(u_ms_hiz_tex, lod);
                    ivec2 a = clamp(ivec2(uv0 * vec2(dim)), ivec2(0), dim - 1);
                    ivec2 b = clamp(ivec2(uv1 * vec2(dim)), ivec2(0), dim - 1);
                    float far = max(max(texelFetch(u_ms_hiz_tex, a, lod).r, texelFetch(u_ms_hiz_tex, ivec2(b.x, a.y), lod).r),
                                    max(texelFetch(u_ms_hiz_tex, ivec2(a.x, b.y), lod).r, texelFetch(u_ms_hiz_tex, b, lod).r));
                    if (nearest * 0.5 + 0.5 > far) {
                        visible = false;
                    }
                }
            }
