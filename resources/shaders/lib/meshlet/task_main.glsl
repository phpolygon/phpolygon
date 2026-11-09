
layout(local_size_x = @GROUP@) in;
shared uint ms_visible;
void main() {
    uint inst = gl_WorkGroupID.y + uint(u_ms_params.y);
    uint m = gl_WorkGroupID.x * @GROUP@u + gl_LocalInvocationIndex;
    if (gl_LocalInvocationIndex == 0u) {
        ms_visible = 0u;
        ms_payload.instance = inst;
    }
    barrier();
    if (m < uint(u_ms_params.x) && inst < uint(u_ms_params.z)) {
        uint ib = inst * 16u;
        mat4 model = mat4(
            vec4(ms_instances[ib], ms_instances[ib + 1u], ms_instances[ib + 2u], 0.0),
            vec4(ms_instances[ib + 4u], ms_instances[ib + 5u], ms_instances[ib + 6u], 0.0),
            vec4(ms_instances[ib + 8u], ms_instances[ib + 9u], ms_instances[ib + 10u], 0.0),
            vec4(ms_instances[ib + 12u], ms_instances[ib + 13u], ms_instances[ib + 14u], 1.0));
        uint mb = m * 8u;
        vec4 c = model * vec4(uintBitsToFloat(ms_meshlets[mb + 4u]), uintBitsToFloat(ms_meshlets[mb + 5u]), uintBitsToFloat(ms_meshlets[mb + 6u]), 1.0);
        float s = max(length(model[0].xyz), max(length(model[1].xyz), length(model[2].xyz)));
        float r = (uintBitsToFloat(ms_meshlets[mb + 7u]) + u_ms_params.w) * s;
        mat4 vp = u_projection * u_view;
        vec4 r0 = vec4(vp[0][0], vp[1][0], vp[2][0], vp[3][0]);
        vec4 r1 = vec4(vp[0][1], vp[1][1], vp[2][1], vp[3][1]);
        vec4 r2 = vec4(vp[0][2], vp[1][2], vp[2][2], vp[3][2]);
        vec4 r3 = vec4(vp[0][3], vp[1][3], vp[2][3], vp[3][3]);
        vec4 planes[6] = vec4[6](r3 + r0, r3 - r0, r3 + r1, r3 - r1, r3 + r2, r3 - r2);
        bool visible = true;
        for (int i = 0; i < 6; i++) {
            if (dot(planes[i].xyz, c.xyz) + planes[i].w < -r * length(planes[i].xyz)) {
                visible = false;
            }
        }
@HIZ_TEST@
        if (visible) {
            uint slot = atomicAdd(ms_visible, 1u);
            ms_payload.meshlets[slot] = m;
        }
    }
    barrier();
    EmitMeshTasksEXT(ms_visible, 1u, 1u);
}
